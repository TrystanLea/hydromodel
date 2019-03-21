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
$data->output_feed("model:hydro_gen");
$data->output_feed("model:solar_gen");
$data->output_feed("model:demand");
$data->output_feed("model:excess");
$data->output_feed("model:unmet");
$data->output_feed("model:backup");
$data->output_feed("model:battery_energy");
$data->output_feed("model:average_balance");
$data->output_feed("model:discharge");
$data->output_feed("model:charge");
$saveoutput = true;

$households = 110;
$oversupply = 1.2;

// $hydro_capacity = 100.00; // Actual hydro capacity
$hydro_capacity = 112.06 * 0.55; // Hydro capacity that matches demand on annual basis
$hydro_capacity *= $oversupply;

$solar_capacity = 4.483 * 0.45 * $households; // 4.48kW per household required for generation to match demand on an annual basis
$solar_capacity *= $oversupply;

// Battery storage
$battery_enabled = true;
$battery_capacity = 7.0*$households;
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
$backup_kwh = 0;

$length = 24*3600;
$sum = 0; $n = 0;
$average_demand = 0;

// Backup capacity examples
// $backup_capacity = 760.0 * $households; //99.99%
//----------------------------------------------
$backup_capacity = 580.0 * $households; //99.90%
// $backup_capacity = 518.0 * $households; //99.80%
// $backup_capacity = 475.0 * $households; //99.70%
// $backup_capacity = 446.0 * $households; //99.60%
// $backup_capacity = 422.0 * $households; //99.50%
//----------------------------------------------
// $backup_capacity = 344.0 * $households; //99.00%

// Model loop
$time = $model_start-$length;
while(true)
{    
    // Calculate running average of balance
    if (floor($time/1800)!=floor(($time-$timestep)/1800)) {
        $h = $data->readp("model:hydro",$time+$length)*$hydro_capacity;
        $s = $data->readp("model:solar1",$time+$length)*$solar_capacity;
        $c = $data->readp("model:community",$time+$length);
        if ($h!==false && $s!==false && $c!==false) { 
            $b = ($h + $s) - $c;
            $sum += $b; 
            $n++; 
        }
        $h = $data->readp("model:hydro",$time-$length)*$hydro_capacity;
        $s = $data->readp("model:solar1",$time-$length)*$solar_capacity;
        $c = $data->readp("model:community",$time-$length);
        if ($h!==false && $s!==false && $c!==false) { 
            $b = ($h + $s) - $c;
            $sum -= $b; 
            $n--; 
        }
        $average_balance = $sum / $n;
    }

    if ($time>=$model_start) 
    {    
        // 1. Read in hydro & solar dataset
        $hydro = $data->read("model:hydro",$time)*$hydro_capacity;
        $solar = $data->read("model:solar1",$time)*$solar_capacity;

        // 2. Read in community consumption profile
        $demand = $data->read("model:community",$time);
        
        // 3. Calculate balance: supply - demand
        $supply = $hydro + $solar;
        $balance = $supply - $demand;
        
        $deviation_from_mean = $balance - $average_balance;
        
        $discharge = 0;
        $charge = 0;
        
        // 4. Battery storage
        if ($battery_enabled) {
            if ($balance>=0.0) {
                // $charge = $balance;
                if ($deviation_from_mean>=0.0) {
                    $charge = ($battery_capacity-$battery_energy)*$deviation_from_mean/($battery_capacity*0.5);
                    if ($charge>$balance) $charge = $balance;
                    
                    $battery_delta = ($charge*0.95 * $timestep)/3600000.0;
                    
                    if ($battery_delta>($battery_capacity-$battery_energy)) {
                        $battery_delta = $battery_capacity - $battery_energy;
                        $charge = ($battery_delta * 3600000.0) / ($timestep * 0.95);
                    }
                    $battery_energy += $battery_delta;
                    
                    $balance -= $charge;
                }
            } else {
                if ($deviation_from_mean<0.0) {
                    // $discharge = -$balance;
                    $discharge = $battery_energy*-$deviation_from_mean/($battery_capacity*0.5);
                    if ($discharge>-$balance) $discharge = -$balance;
                    
                    $battery_delta = (($discharge/0.95) * $timestep)/3600000.0;
                    
                    if ($battery_delta>$battery_energy) {
                        $battery_delta = $battery_energy;
                        $discharge = ($battery_delta * 3600000.0) / ($timestep * 0.95);
                    }
                    $battery_energy -= $battery_delta;
                    
                    $balance += $discharge;
                }
            }
        }
        
        $backup = 0.0;
        if ($balance<0.0) {
            $backup = -$balance;
            if ($backup>$backup_capacity) $backup = $backup_capacity;
            $balance += $backup;
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
        $backup_kwh  += ($backup  * $timestep) / 3600000.0;
        
        // 7. Write output to output feed  
        if ($saveoutput) {
            $data->write("model:hydro_gen",$hydro);
            $data->write("model:solar_gen",$solar);
            $data->write("model:supply",$supply);
            $data->write("model:demand",$demand);
            $data->write("model:excess",$excess);
            $data->write("model:unmet",$unmet);
            $data->write("model:backup",$backup);
            $data->write("model:battery_energy",$battery_energy);
            $data->write("model:average_balance",$average_balance);
            $data->write("model:discharge",$discharge);
            $data->write("model:charge",$charge);
        }
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
print str_pad("Unmet:",$pad).round($unmet_kwh)." kWh\n";
print str_pad("Backup:",$pad).round($backup_kwh)." kWh\n";
print str_pad("Demand supplied after backup:",$pad).number_format($met_demand_prc*100,2)." %\n";
print str_pad("Renewable self consumption:",$pad).number_format($renewable_self_use_prc*100,2)." %\n";
print str_pad("Average CO2 Intensity:",$pad).round($co2_intensity)." gCO2/kWh\n";

// Save output feeds
if ($saveoutput) $data->save_all();
