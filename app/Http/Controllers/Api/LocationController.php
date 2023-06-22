<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $locations = Location::orderBy('name', 'asc')->get();
            return response([
                'message' => 'success',
                'total_rows' => $locations->count(),
                'locations' => $locations,
            ]);
        } catch (Exception $e) {
            report($e);
            return response([
                'message' => 'Fail to get result.',
                'total_rows' => 0,
                'locations' => null,
            ], 500);
        }
    }

    public function show($id)
    {
        $location = Location::find($id);

        if (is_null($location)) {
            return response(['message' => 'Location not found.'], 404);
        }
        return response(['location' => $location]);
    }

    public function create()
    {
        return response();
    }

    public function store(Request $request)
    {
        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Location is required.',
        ]);

        Validator::make(json_decode($request->data, true), [
            'branch_id' => 'required',
            'name' => 'required',
        ])->validate();

        $jlocation = json_decode($request->data);

        try {
            $location = Location::create([
                'branch_id' => $jlocation->branch_id,
                'name' => $jlocation->name,
            ]);

            return response(['location' => $location]);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }
    }

    public function edit($id)
    {
        $location = Location::find($id);

        if (is_null($location)) {
            return response(['message' => 'Location not found.'], 404);
        }

        return response([
            'location' => $location,
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'data' => 'required',
        ], [
            'data.required' => 'Location is required.',
        ]);

        $location = Location::find($id);

        if (is_null($location)) {
            return response(['message' => 'Location not found.'], 404);
        }

        Validator::make(json_decode($request->data, true), [
            'branch_id' => 'required',
            'name' => 'required',
        ])->validate();

        $jlocation = json_decode($request->data);
        try {
            $location->update([
                'branch_id' => $jlocation->branch_id,
                'name' => $jlocation->name,
            ]);
            return response(['location' => $location]);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $location = Location::find($id);

            if (is_null($location)) {
                return response(['message' => 'Location not found.'], 404);
            }

            $location->destroy($id);
        } catch (QueryException $sqlex) {
            report($sqlex);
            return response(['message' => 'Record cannot be deleted.'], 500);
        } catch (Exception $e) {
            report($e);
            return response(['message' => 'Internal server error occurred.'], 500);
        }
        return response(['message' => 'Location is deleted.']);
    }
}
