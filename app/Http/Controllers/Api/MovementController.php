<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Movement;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MovementController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->has('per_page') ? $request->get('per_page') : 25;
        if ($perPage > 25) {
            $perPage = 25;
        }

        $sortBy = ($request->has('sort_by') && !is_null($request->get('sort_by'))) ? $request->get('sort_by') : 'record_date';
        $sortDirection = ($request->has('sort_direction') && !is_null($request->get('sort_direction'))) ? $request->get('sort_direction') : 'desc';

        $filter = $request->has('filter') ? $request->get('filter') : '';
        $asset_id = $request->has('asset_id') ? $request->get('asset_id') : '';
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        try {
            $paginator = Movement::whereHas('location.branch', function (Builder $query) {
                $query->where('organization_id', request()->user()->organization_id);
            })
                ->when($filter != '', function ($query, $check_result) use ($filter) {
                    return $query->whereHas('asset', function (Builder $query) use ($filter) {
                        $query->where('name', env('DB_CONNECTION') == 'pgsql' ? 'ILIKE' : 'LIKE', "%$filter%")
                            ->orWhere('group', env('DB_CONNECTION') == 'pgsql' ? 'ILIKE' : 'LIKE', "%$filter%");
                    });
                })
                ->when($asset_id != '', function ($query, $check_result) use ($asset_id) {
                    return $query->where('asset_id', $asset_id);
                })
                ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->whereHas('asset', function (Builder $q) {
                            $q->where('group', 'LVT');
                        });
                    } else {
                        return $query->whereHas('asset', function (Builder $q) {
                            $q->where('group', '<>', 'LVT');
                        });
                    }
                })
                ->with(['asset', 'location', 'to_location'])
                ->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return response([
                'message' => 'success',
                'total_rows' => $paginator->total(),
                'movements' => $paginator->items(),
            ]);
        } catch (Exception $ex) {
            report($ex);
            return response([
                'message' => 'Fail to get result.',
                'total_rows' => 0,
                'movements' => null,
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $movement = Movement::whereHas('location.branch', function (Builder $query) {
            $query->where('organization_id', request()->user()->organization_id);
        })
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->whereHas('asset', function (Builder $q) {
                        $q->where('group', 'LVT');
                    });
                } else {
                    return $query->whereHas('asset', function (Builder $q) {
                        $q->where('group', '<>', 'LVT');
                    });
                }
            })
            ->with(['asset', 'location', 'to_location'])
            ->find($id);

        if (is_null($movement)) {
            return response(['message' => 'Movement not found.'], 404);
        }

        return response(['movement' => $movement]);
    }

    public function create(Request $request)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        return response([
            'locations' => Location::orderBy('name', 'asc')->get(),
            'assets' => Asset::when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })->orderBy('name', 'asc')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Movement is required.',
        ]);

        Validator::make(json_decode($request->data, true), [
            'record_date' => 'required',
            'asset_id' => 'required',
            'location_id' => 'required',
            'movement_type' => 'required|in:opening,moved,disposed',
            'quantity' => 'required',
        ])->validate();

        $jmovement = json_decode($request->data);
        $jmovement->organization_id = $request->user()->organization_id;

        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->find($jmovement->asset_id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        $movement = null;
        try {
            DB::transaction(function () use ($jmovement, $request, $asset, &$movement) {
                $q = $asset->quantity + (int) $jmovement->quantity;
                $movement = Movement::create([
                    'record_date' => $jmovement->record_date,
                    'asset_id' => $jmovement->asset_id,
                    'location_id' => $jmovement->location_id,
                    'to_location_id' => isset($jmovement->to_location_id) ? $jmovement->to_location_id : null,
                    'movement_type' => $jmovement->movement_type,
                    'quantity' => $jmovement->quantity,
                    'recorded_by' => $request->user()->id,
                    'remark' => isset($jmovement->details) ? $jmovement->details : null,
                ]);

                if ($jmovement->movement_type == 'opening') {
                    $asset->update([
                        'quantity' => $q,
                    ]);
                }

                $asset_location = AssetLocation::where('asset_id', $asset->id)->where('location_id', $jmovement->location_id)->first();
                if (is_null($asset_location)) {
                    $asset_location = AssetLocation::create([
                        'branch_id' => 1,
                        'asset_id' => $jmovement->asset_id,
                        'location_id' => $jmovement->location_id,
                        'quantity' => $jmovement->quantity,
                    ]);
                } else {
                    $asset_location->update([
                        'quantity' => $asset_location->quantity + (int) $jmovement->quantity,
                    ]);
                }
            }, 5);
            return response(['movement' => $movement]);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error ocurred.'], 500);
        }
    }

    public function edit(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $movement = Movement::whereHas('location.branch', function (Builder $query) {
            $query->where('organization_id', request()->user()->organization_id);
        })
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->whereHas('asset', function (Builder $q) {
                        $q->where('group', 'LVT');
                    });
                } else {
                    return $query->whereHas('asset', function (Builder $q) {
                        $q->where('group', '<>', 'LVT');
                    });
                }
            })
            ->with(['asset', 'location', 'to_location'])
            ->find($id);

        if (is_null($movement)) {
            return response(['message' => 'Movement not found.'], 404);
        }

        return response(['movement' => $movement,
            'locations' => Location::orderBy('name', 'asc')->get(),
            'assets' => Asset::when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })->orderBy('name', 'asc')->get()]);
    }

    public function update(Request $request, $id)
    {
        $movement = Movement::whereHas('location.branch', function (Builder $query) {
            $query->where('organization_id', request()->user()->organization_id);
        })
            ->with(['asset', 'location', 'to_location'])
            ->find($id);

        if (is_null($movement)) {
            return response(['message' => 'Movement not found.'], 404);
        }

        if ($request->user()->user_role != 'admin' && $movement->recorded_by != $request->user()->id) {
            return response(['message' => 'You are not allowed to edit this record.'], 422);
        }

        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Movement is required.',
        ]);

        Validator::make(json_decode($request->data, true), [
            'record_date' => 'required',
            'asset_id' => 'required',
            'location_id' => 'required',
            'movement_type' => 'required|in:opening,moved,disposed',
            'quantity' => 'required',
        ])->validate();

        $jmovement = json_decode($request->data);
        $jmovement->organization_id = $request->user()->organization_id;

        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->find($jmovement->asset_id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        try {
            DB::transaction(function () use ($jmovement, $request, &$asset, &$movement) {
                $q = (int) $asset->quantity - (int) $movement->quantity + (int) $jmovement->quantity;

                if ($jmovement->movement_type == 'opening') {
                    $asset->update([
                        'quantity' => $q,
                    ]);

                    $old = AssetLocation::where('asset_id', $asset->id)->where('location_id', $movement->location_id)->first();
                    $old->update([
                        'quantity' => $old->quantity - (int) $movement->quantity,
                    ]);

                    $asset_location = AssetLocation::where('asset_id', $asset->id)->where('location_id', $jmovement->location_id)->first();
                    if (is_null($asset_location)) {
                        $asset_location = AssetLocation::create([
                            'branch_id' => 1,
                            'asset_id' => $jmovement->asset_id,
                            'location_id' => $jmovement->location_id,
                            'quantity' => $jmovement->quantity,
                        ]);
                    } else {
                        $asset_location->update([
                            'quantity' => $asset_location->quantity + (int) $jmovement->quantity,
                        ]);
                    }
                }

                $movement->update([
                    'record_date' => $jmovement->record_date,
                    'asset_id' => $jmovement->asset_id,
                    'location_id' => $jmovement->location_id,
                    'to_location_id' => isset($jmovement->to_location_id) ? $jmovement->to_location_id : null,
                    'movement_type' => $jmovement->movement_type,
                    'quantity' => $jmovement->quantity,
                    'remark' => isset($jmovement->details) ? $jmovement->details : null,
                ]);

            }, 5);
            return response(['movement' => $movement]);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error ocurred.'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $movement = Movement::whereHas('location.branch', function (Builder $query) {
            $query->where('organization_id', request()->user()->organization_id);
        })
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->whereHas('asset', function (Builder $q) {
                        $q->where('group', 'LVT');
                    });
                } else {
                    return $query->whereHas('asset', function (Builder $q) {
                        $q->where('group', '<>', 'LVT');
                    });
                }
            })
            ->with(['asset', 'location', 'to_location'])
            ->find($id);

        if (is_null($movement)) {
            return response(['message' => 'Movement not found.'], 404);
        }

        if ($request->user()->user_role != 'admin' && $movement->recorded_by != $request->user()->id) {
            return response(['message' => 'You are not allowed to edit this record.'], 422);
        }

        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->find($movement->asset_id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        try {
            if ($movement->movement_type == 'opening') {
                $asset->update([
                    'quantity' => $asset->quantity - $movement->quantity,
                ]);

                $old = AssetLocation::where('asset_id', $asset->id)->where('location_id', $movement->location_id)->first();
                $old->update([
                    'quantity' => $old->quantity - (int) $movement->quantity,
                ]);
            }
            $movement->destroy($id);
        } catch (QueryException $sqlex) {
            report($sqlex);
            return response(['message' => 'Record cannot be deleted.'], 500);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }

        return response(['message' => 'Movement deleted']);
    }
}
