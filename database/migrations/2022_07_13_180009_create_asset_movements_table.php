<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssetMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->timestamp('record_date');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->enum('movement_type', ['opening', 'moved', 'disposed'])->default('opening');
            $table->integer('quantity');
            $table->integer('recorded_by');
            $table->string('remark')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict')->onUpdate('cascade');
            $table->foreign('to_location_id')->references('id')->on('locations')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('asset_movements');
    }
}
