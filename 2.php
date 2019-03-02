<?php
// ------------------------------------------------------------------------------
// Community Hydro Matching - with battery
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

$data->output_feed("model:supply");
$data->output_feed("model:demand");
$data->output_feed("model:excess");
$data->output_feed("model:unmet");
$data->output_feed("model:battery_energy");

// $hydro_capacity = 100.00; // Actual hydro capacity
$hydro_capacity = 112.06; // Hydro capacity that matches demand on annual basis
// Oversupply factor
$hydro_capacity *= 1.0;

// Battery storage
$battery_capacity = 7.0*110;
$battery_energy = $battery_capacity * 0.5;

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
    
    // -------------------------------------------------------------------------------
    // Battery storage
    // -------------------------------------------------------------------------------
    if ($balance>0) {
        $charge = $balance;
        $battery_delta = ($charge*0.95 * $timestep)/3600000.0;
        
        if (($battery_energy+$battery_delta)<=$battery_capacity) {
            $battery_energy += $battery_delta;
        } else {
            $charge = 0;
        }
        
        $balance -= $charge;
    } else {
        $discharge = -$balance;
        $battery_delta = (($discharge/0.95) * $timestep)/3600000.0;
        
        if (($battery_energy-$battery_delta)>=0) {
            $battery_energy -= $battery_delta;
        } else {
            $discharge = 0;
        }
        
        $balance += $discharge;
    }
    
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
    
    // 6. Write output to output feed  
    $data->write("model:supply",$supply);
    $data->write("model:demand",$demand);
    $data->write("model:excess",$excess);
    $data->write("model:unmet",$unmet);
    $data->write("model:battery_energy",$battery_energy);
    
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
print str_pad("Average CO2 Intensity:",$pad).round($co2_intensity)."gCO2/kWh\n";
// Save output feeds
$data->save_all();
