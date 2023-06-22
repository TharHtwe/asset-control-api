<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateAssetLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('location_id');
            $table->integer('quantity');
            $table->timestamps();
        });

        DB::statement("INSERT INTO asset_locations (branch_id, asset_id, location_id, quantity) (SELECT 1, asset_id, location_id, sum(quantity) from asset_movements group by asset_id, location_id)");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('asset_locations');
    }
}
