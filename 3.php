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
$data->input_feed("model:solar1",0);      // Normalised solar dataset
$data->input_feed("model:community",0);   // Bethesda community dataset ~110 households

$data->output_feed("model:supply");
$data->output_feed("model:demand");
$data->output_feed("model:excess");
$data->output_feed("model:unmet");
$data->output_feed("model:battery_energy");
$saveoutput = true;

$households = 110;
$oversupply = 1.2;

// $hydro_capacity = 100.00; // Actual hydro capacity
$hydro_capacity = 112.06 * 0.55; // Hydro capacity that matches demand on annual basis
$hydro_capacity *= $oversupply;

$solar_capacity = 4.483 * 0.45 * $households; // 4.48kW per household required for generation to match demand on an annual basis
$solar_capacity *= $oversupply * 1.6;

// Battery storage
$battery_enabled = true;
$battery_capacity = 100.0*$households;
$battery_energy = $battery_capacity * 0.5; // starting capacity

// co2 intensity of electric assuming 2018 average grid intensity
$import_intensity = 283.0;

// cumulative kWh 
$solar_kwh = 0;
$hydro_kwh = 0;
$supply_kwh = 0;
$demand_kwh = 0;
$excess_kwh = 0;
$unmet_kwh = 0;

// Model loop
$time = $model_start;
while(true)
{    
    // 1. Read in hydro & solar dataset
    $hydro = $data->read("model:hydro",$time)*$hydro_capacity;
    $solar = $data->read("model:solar1",$time)*$solar_capacity;

    // 2. Read in community consumption profile
    $demand = $data->read("model:community",$time);
    
    // 3. Calculate balance: supply - demand
    $supply = $hydro + $solar;
    $balance = $supply - $demand;
    
    // 4. Battery storage
    if ($battery_enabled) {
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
    }
        
    // 5. Calculate excess hydro and unmet demand
    $excess = 0;
    $unmet = 0;
    if ($balance>0.0) {
        $excess = $balance;
    } else {
        $unmet = -1 * $balance;
    }
    
    // 6. Cumulative kWh calculation
    $hydro_kwh  += ($hydro  * $timestep) / 3600000.0;
    $solar_kwh  += ($solar  * $timestep) / 3600000.0;
    $supply_kwh += ($supply * $timestep) / 3600000.0;
    $demand_kwh += ($demand * $timestep) / 3600000.0;
    $excess_kwh += ($excess * $timestep) / 3600000.0;
    $unmet_kwh  += ($unmet  * $timestep) / 3600000.0;
    
    // 7. Write output to output feed  
    if ($saveoutput) {
        $data->write("model:supply",$supply);
        $data->write("model:demand",$demand);
        $data->write("model:excess",$excess);
        $data->write("model:unmet",$unmet);
        $data->write("model:battery_energy",$battery_energy);
    }
    
    // Keep track of time and model end
    $time += $timestep;
    if ($time>$model_end) break;
}

$oversupply = $supply_kwh/$demand_kwh;
$renewable_self_use = $supply_kwh - $excess_kwh;
$renewable_self_use_prc = $renewable_self_use / $supply_kwh;
$met_demand_prc = ($demand_kwh - $unmet_kwh) / $demand_kwh;
$co2_intensity = ($unmet_kwh*$import_intensity) / $demand_kwh;

if ($hydro_capacity>0) $hydro_CF = $hydro_kwh / ($hydro_capacity*365*24); else $hydro_CF = 0;
if ($solar_capacity>0) $solar_CF = $solar_kwh / ($solar_capacity*365*24); else $solar_CF = 0;

// Final results
$pad = 35;
print str_pad("Hydro:",$pad).round($hydro_kwh)." kWh (".number_format($hydro_CF*100,1)."%)\n";
print str_pad("Solar:",$pad).round($solar_kwh)." kWh (".number_format($solar_CF*100,1)."%)\n";
print str_pad("Renewable generation:",$pad).round($supply_kwh)." kWh\n";
print str_pad("Community demand:",$pad).round($demand_kwh)." kWh\n";
print str_pad("Oversupply:",$pad).number_format($oversupply*100,1)." %\n";
print str_pad("Excess:",$pad).round($excess_kwh)." kWh\n";
print str_pad("Unmet/backup:",$pad).round($unmet_kwh)." kWh\n";
print str_pad("Demand supplied before backup:",$pad).number_format($met_demand_prc*100,2)." %\n";
print str_pad("Renewable self consumption:",$pad).number_format($renewable_self_use_prc*100,2)." %\n";
print str_pad("Average CO2 Intensity:",$pad).round($co2_intensity)." gCO2/kWh\n";

// Save output feeds
if ($saveoutput) $data->save_all();
