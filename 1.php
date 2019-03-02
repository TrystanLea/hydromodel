<?php
// ------------------------------------------------------------------------------
// Community Hydro Matching
// ------------------------------------------------------------------------------
$emoncms_dir = "/var/www/emoncms/";
$model_start = 1501545600;
$model_duration = 3600*24*365;
$model_end = $model_start + $model_duration;
$timestep = 60;

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,$model_start,$timestep);
$data->input_feed("model:hydro",0);       // Normalised hydro dataset
$data->input_feed("model:community",0);   // Bethesda community dataset ~110 households

$hydro_capacity = 100.00; // Actual hydro capacity
// $hydro_capacity = 112.06; // Hydro capacity that matches demand on annual basis
// Oversupply factor
$hydro_capacity *= 1.0;

$supply_kwh = 0;
$demand_kwh = 0;
$excess_kwh = 0;
$unmet_kwh = 0;

// Model loop
$time = $model_start;
while(true)
{    
    // 1. Read in hydro dataset
    $hydro = $data->read("model:hydro",$time)*$hydro_capacity;

    // 2. Read in community consumption profile
    $demand = $data->read("model:community",$time);
    
    // 3. Calculate balance: supply - demand
    $supply = $hydro;
    $balance = $supply - $demand;
    
    // 4. Calculate excess hydro and unmet demand
    $excess = 0;
    $unmet = 0;
    if ($balance>0.0) {
        $excess = $balance;
    } else {
        $unmet = -1 * $balance;
    }
    
    // 5. Cumulative kWh calculation
    $supply_kwh += ($supply * $timestep) / 3600000.0;
    $demand_kwh += ($demand * $timestep) / 3600000.0;
    $excess_kwh += ($excess * $timestep) / 3600000.0;
    $unmet_kwh  += ($unmet  * $timestep) / 3600000.0;
    
    // Keep track of time and model end
    $time += $timestep;
    if ($time>$model_end) break;
}

$renewable_self_use = $supply_kwh - $excess_kwh;
$renewable_self_use_prc = $renewable_self_use / $supply_kwh;
$met_demand_prc = ($demand_kwh - $unmet_kwh) / $demand_kwh;
// co2 intensity of electric assuming 2018 average grid intensity
$co2_intensity = ($unmet_kwh*283.0) / $demand_kwh;

// Final results
$pad = 30;
print str_pad("Hydro generation:",$pad).round($supply_kwh)." kWh\n";
print str_pad("Community demand:",$pad).round($demand_kwh)." kWh\n";
print str_pad("Excess Hydro:",$pad).round($excess_kwh)." kWh\n";
print str_pad("Grid Import:",$pad).round($unmet_kwh)." kWh\n";
print str_pad("Demand supplied by hydro:",$pad).number_format($met_demand_prc*100,1)." %\n";
print str_pad("Hydro self consumption:",$pad).number_format($renewable_self_use_prc*100,1)." %\n";
print str_pad("Average CO2 Intensity:",$pad).round($co2_intensity)." gCO2/kWh\n";
