<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetHistory;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Location;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->has('per_page') ? $request->get('per_page') : 25;
        if ($perPage > 25) {
            $perPage = 25;
        }

        $sortBy = ($request->has('sort_by') && !is_null($request->get('sort_by'))) ? $request->get('sort_by') : 'name';
        $sortDirection = ($request->has('sort_direction') && !is_null($request->get('sort_direction'))) ? $request->get('sort_direction') : 'asc';

        $filter = $request->has('filter') ? $request->get('filter') : '';
        $groups = $request->has('groups') ? $request->get('groups') : '';
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        try {
            // SELECT assets.id, assets.name, GROUP_CONCAT(' ', location_name, ' => ', total) AS asset_locations FROM assets INNER JOIN (
            //     SELECT asset_id, location_id, locations.name AS location_name, SUM(quantity) AS total FROM asset_movements
            //     INNER JOIN locations ON locations.id = location_id
            //     GROUP BY asset_id, location_id
            //     ) AS summarized ON assets.id = summarized.asset_id
            //     GROUP BY assets.id, assets.name

            // $summarized = DB::table('asset_movements')
            //     ->selectRaw("asset_id, location_id, locations.name AS `location_name`, SUM(quantity) AS `total`")
            //     ->join('locations', 'locations.id', '=', 'location_id')
            //     ->groupBy('asset_id', 'location_id');
            // ->having('occurences', '>', '1');

            // $paginator = Asset::select('assets.*', DB::raw("REPLACE(TRIM(GROUP_CONCAT(' ', location_name, ' => ', total)), ',', '<br>') AS `asset_locations`"))
            //     ->joinSub($summarized, 'summarized', function ($join) {
            //         $join->on('assets.id', '=', 'summarized.asset_id');
            //         // ->on('members.name', '=', 'duplicates.dname');
            //     })
            //     ->when($filter != '', function ($query, $check_result) use ($filter) {
            //         return $query->where('name', env('DB_CONNECTION') == 'pgsql' ? 'ILIKE' : 'LIKE', "%$filter%")
            //             ->orWhere('group', env('DB_CONNECTION') == 'pgsql' ? 'ILIKE' : 'LIKE', "%$filter%");
            //     })
            //     ->when($groups != '', function ($query, $check_result) use ($groups) {
            //         return $query->whereIn('group', explode(',', $groups));
            //     })
            //     ->groupBy('assets.id', 'assets.name')
            //     ->orderBy($sortBy, $sortDirection)
            //     ->paginate($perPage);

            $summarized = DB::table('asset_locations')
                ->selectRaw("asset_id, location_id, locations.name AS location_name, quantity")
                ->join('locations', 'locations.id', '=', 'location_id');

            $paginator = Asset::select('assets.*', env('DB_CONNECTION') == 'pgsql' ? DB::raw("STRING_AGG(CONCAT_WS( ' ' , location_name, ' => ', summarized.quantity),'<br> ') AS asset_locations") : DB::raw("REPLACE(TRIM(GROUP_CONCAT(' ', location_name, ' => ', summarized.quantity)), ',', '<br>') AS `asset_locations`"))
                ->leftJoinSub($summarized, 'summarized', function ($join) {
                    $join->on('assets.id', '=', 'summarized.asset_id');
                    // ->on('members.name', '=', 'duplicates.dname');
                })
                ->when($filter != '', function ($query, $check_result) use ($filter) {
                    return $query->where('name', env('DB_CONNECTION') == 'pgsql' ? 'ILIKE' : 'LIKE', "%$filter%");
                        //->orWhere('group', env('DB_CONNECTION') == 'pgsql' ? 'ILIKE' : 'LIKE', "%$filter%");
                })
                ->when($groups != '', function ($query, $check_result) use ($groups) {
                    return $query->whereIn('group', explode(',', $groups));
                })
                ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->where('group', 'LVT');
                    } else {
                        return $query->where('group', '<>', 'LVT');
                    }
                })
                ->groupBy('assets.id', 'assets.name')
                ->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return response([
                'message' => 'success',
                'total_rows' => $paginator->total(),
                'assets' => $paginator->items(),
            ]);
        } catch (Exception $ex) {
            report($ex);
            return response([
                'message' => 'Fail to get result.',
                'total_rows' => 0,
                'assets' => null,
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })
            ->with(['organization', 'histories', 'movements'])
            ->find($id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        return response(['asset' => $asset]);
    }

    public function create(Request $request)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        return response([
            'groups' => Asset::select('group')->whereNotNull('group')
                ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->where('group', 'LVT');
                    } else {
                        return $query->where('group', '<>', 'LVT');
                    }
                })->orderBy('group')->distinct()->get(),
            'locations' => Location::get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Asset is required.',
        ]);

        Validator::make(json_decode($request->data, true), [
            'name' => 'required',
            'status' => 'required',
        ])->validate();

        $jasset = json_decode($request->data);
        $jasset->organization_id = $request->user()->organization_id;

        $asset = null;
        if (isset($jasset->photo)) {
            $photo = base64_decode(explode(",", explode(";", $jasset->photo)[1])[1]);
            $ext = str_replace("jpeg", "jpg", str_replace("data:image/", ".", explode(";", $jasset->photo)[0]));
            $random = Str::random(40);
            $temp = 'upload/temp/' . Str::random(40);
            Storage::put($temp . $ext, $photo);
            $path = 'asset/photos/' . $random . $ext;
            Storage::disk(config('asset.storage_disk'))->put($path, file_get_contents($jasset->photo));
            $jasset->photo = $path;
            Storage::delete($temp . $ext);
        }

        try {
            DB::transaction(function () use ($jasset, $request, &$asset) {
                $asset = Asset::create([
                    'organization_id' => $jasset->organization_id,
                    'code' => isset($jasset->code) && !empty($jasset->code) ? $jasset->code : null,
                    'name' => $jasset->name,
                    'alternative_name' => isset($jasset->alternative_name) ? $jasset->alternative_name : null,
                    'group' => isset($jasset->group) ? $jasset->group : null,
                    'photo' => isset($jasset->photo) ? $jasset->photo : null,
                    'serial_no' => isset($jasset->serial_no) ? $jasset->serial_no : null,
                    'details' => isset($jasset->details) ? $jasset->details : null,
                    'warranty_end' => isset($jasset->warranty_end) ? $jasset->warranty_end : null,
                    'status' => $jasset->status,
                    'summarize_by_group' => $jasset->summarize_by_group,
					'summarize_by' => isset($jasset->summarize_by) ? $jasset->summarize_by : null,
                ]);
            }, 5);
            return response(['asset' => $asset]);
        } catch (QueryException $sqlex) {
            report($sqlex);
            return response(['message' => 'Duplicate code detected.'], 500);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error ocurred.'], 500);
        }
    }

    public function edit(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })
            ->with(['organization', 'histories', 'movements.location'])
            ->find($id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        return response([
			'asset' => $asset,
            'groups' => Asset::select('group')->whereNotNull('group')
                ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->where('group', 'LVT');
                    } else {
                        return $query->where('group', '<>', 'LVT');
                    }
                })->orderBy('group')->distinct()->get(),
            'employees' => Employee::orderBy('name', 'asc')->get(),
            'locations' => Location::orderBy('name', 'asc')->get()]);
    }

    public function update(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })
            ->with(['organization'])
            ->find($id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Asset is required.',
        ]);
        // Log::debug(['data' => $request->data]);
        Validator::make(json_decode($request->data, true), [
            'name' => 'required',
            'status' => 'required',
        ])->validate();

        $jasset = json_decode($request->data);

        if (isset($jasset->photo) && !Str::contains($jasset->photo, config('asset.storage_url'))) {
            if (!empty($asset->getRawOriginal('photo'))) {
                Storage::disk(config('asset.storage_disk'))->delete($asset->getRawOriginal('photo'));
            }

            $photo = base64_decode(explode(",", explode(";", $jasset->photo)[1])[1]);
            $ext = str_replace("jpeg", "jpg", str_replace("data:image/", ".", explode(";", $jasset->photo)[0]));
            $random = Str::random(40);
            $temp = 'upload/temp/' . Str::random(40);
            Storage::put($temp . $ext, $photo);
            $path = 'asset/photos/' . $random . $ext;
            Storage::disk(config('asset.storage_disk'))->put($path, file_get_contents($jasset->photo));
            $jasset->photo = $path;
            Storage::delete($temp . $ext);
        }

        try {
            DB::transaction(function () use ($jasset, $request, &$asset) {
                $asset->update([
                    'name' => $jasset->name,
                    'code' => isset($jasset->code) && !empty($jasset->code) ? $jasset->code : null,
                    'alternative_name' => isset($jasset->alternative_name) ? $jasset->alternative_name : null,
                    'group' => isset($jasset->group) ? $jasset->group : null,
                    'photo' => isset($jasset->photo) ? (Str::contains($jasset->photo, config('asset.storage_url')) ? $asset->getRawOriginal('photo') : $jasset->photo) : null,
                    'serial_no' => isset($jasset->serial_no) ? $jasset->serial_no : null,
                    'details' => isset($jasset->details) ? $jasset->details : null,
                    'warranty_end' => isset($jasset->warranty_end) ? $jasset->warranty_end : null,
                    'status' => $jasset->status,
                    'summarize_by_group' => $jasset->summarize_by_group,
					'summarize_by' => isset($jasset->summarize_by) ? $jasset->summarize_by : null,
                ]);
            }, 5);
            return response(['asset' => $asset]);
        } catch (QueryException $sqlex) {
            report($sqlex);
            return response(['message' => 'Duplicate code detected.'], 500);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error ocurred.'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $scope = $request->has('scope') ? $request->get('scope') : 'ctu';
        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })
            ->with(['organization'])
            ->find($id);

        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        try {
            $asset->destroy($id);
        } catch (QueryException $sqlex) {
            report($sqlex);
            return response(['message' => 'Record cannot be deleted.'], 500);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }

        return response(['message' => 'Asset deleted']);
    }

    public function checkout(Request $request, $id)
    {
        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Asset is required.',
        ]);

        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->find($id);
        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        $jcheckout = json_decode($request->data);
        Validator::make(json_decode($request->data, true), [
            'asset_id' => 'required',
            'checkout_date' => 'required',
            'checkout_type' => 'required',
            'checkout_to' => 'required',
            'quantity' => 'required',
        ])->validate();

        $asset_history = null;
        try {
            DB::transaction(function () use ($jcheckout, &$asset_history, &$asset, $request) {
                $emp = null;
                if (strtolower($jcheckout->checkout_type) == 'assigned') {
                    $emp = Employee::whereIn('branch_id', Branch::where('organization_id', $request->user()->organization_id)->select('id'))->where('name', $jcheckout->checkout_to)->first();
                    if ($emp == null) {
                        $emp = Employee::create([
                            'branch_id' => $request->organization_id,
                            'name' => $jcheckout->checkout_to,
                        ]);
                    }
                }

                $asset_history = AssetHistory::create([
                    'asset_id' => $jcheckout->asset_id,
                    'employee_id' => strtolower($jcheckout->checkout_type) == 'assigned' ? $emp->id : null,
                    'start_date' => $jcheckout->checkout_date,
                    'checkout_type' => $jcheckout->checkout_type,
                    'quantity' => $jcheckout->quantity,
                    'remark' => isset($jcheckout->remark) ? $jcheckout->remark : null,
                ]);
            }, 5);
            return response(['checkout' => $asset_history]);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }
    }

    public function checkin(Request $request, $id)
    {
        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Asset is required.',
        ]);

        $asset = Asset::where('organization_id', $request->user()->organization_id)
            ->find($id);
        if (is_null($asset)) {
            return response(['message' => 'Asset not found.'], 404);
        }

        $jcheckin = json_decode($request->data);
        Validator::make(json_decode($request->data, true), [
            'id' => 'required',
            'asset_id' => 'required',
            'checkin_date' => 'required',
        ])->validate();

        $asset_history = AssetHistory::find($jcheckin->id);
        try {
            DB::transaction(function () use ($jcheckin, &$asset_history, &$asset, $request) {
                $asset_history->update([
                    'end_date' => $jcheckin->checkin_date,
                    'checkined' => true,
                    'remark' => isset($jcheckin->remark) ? $jcheckin->remark : null,
                ]);
            }, 5);
            return response(['checkin' => $asset_history]);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }
    }
}
