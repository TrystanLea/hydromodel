<?php
define('EMONCMS_EXEC', 1);
    
class ModelHelper
{
    private $dir = "/var/lib/phpfina/";
    private $start_time = 0;
    private $interval = 60;
    
    private $buffer = array();
    private $output = array();
    private $m = array();       // meta
    private $fh = array();      // file handler
    private $p = array();       // position
    private $p_av = array();    // position average fn
    private $smooth = array();  // smooth
    private $value = array();  // smooth
    private $smooth_value = array();  // smooth
    
    private $pA = array();
    private $pB = array();
    
    private $sum = array();
    private $sum_count = array();
    
    public $feed = false;
    private $userid = 1;

    // ------------------------------------------------------------------------------
    // Methods
    // ------------------------------------------------------------------------------

    // ------------------------------------------------------------------------------
    // Construct
    // 1. Load emoncms instance settings
    // 2. Load Mysqli and Redis
    // 3. Load Feed model    
    // ------------------------------------------------------------------------------
    public function __construct($emoncms_dir,$start_time,$interval)
    {
        $this->start_time = $start_time;
        $this->interval = $interval;
        
        chdir($emoncms_dir);
        
        require_once "settings.php";
        $this->dir = $feed_settings["phpfina"]["datadir"];
        
        $mysqli = @new mysqli($server,$username,$password,$database,$port);
        if ($mysqli->connect_error) {
            echo $mysqli->connect_error."\n"; die;
        }
        
        // 2) Database
        if ($redis_enabled) {
            $redis = new Redis();
            $connected = $redis->connect($redis_server['host'], $redis_server['port']);
            if (!$connected) { echo "Can't connect to redis at ".$redis_server['host'].":".$redis_server['port']; die; }
            if (!empty($redis_server['prefix'])) $redis->setOption(Redis::OPT_PREFIX, $redis_server['prefix']);
            if (!empty($redis_server['auth'])) {
                if (!$redis->auth($redis_server['auth'])) {
                    echo "Can't connect to redis at ".$redis_server['host'].", autentication failed"; die;
                }
            }
        } else {
            $redis = false;
        }
        
        require_once "Lib/enum.php";
        require_once "Lib/EmonLogger.php";
        require_once "Modules/feed/feed_model.php";
        $this->feed = new Feed($mysqli,$redis,$feed_settings);
    }

    // ------------------------------------------------------------------------------
    // Register input feed
    // 1. Check if feed exists, fetch feed id and meta data
    // 2. Save smoothing setting
    // ------------------------------------------------------------------------------
    public function input_feed($tagname,$smooth) {
    
        $name = $tagname;    
        $tagname = explode(":",$tagname);
        
        if (!$id = $this->feed->exists_tag_name($this->userid,$tagname[0],$tagname[1])) {
            echo "Feed does not exist $name\n"; die;
        }
        
        $this->m[$name] = $this->feed->get_meta($id);
        $this->fh[$name] = @fopen($this->dir.$id.".dat", 'rb');
        $this->p[$name] = -1;
        $this->p_av[$name] = -1;
        $this->pA[$name] = 0;
        $this->pB[$name] = 0;
        $this->smooth[$name] = $smooth;
        $this->value[$name] = 0;
        $this->smooth_value[$name] = 0;
        $this->sum[$name] = 0;
        $this->sum_count[$name] = 0;        
    }

    // ------------------------------------------------------------------------------
    // Register output feed
    // 1. Check if output feed already exists
    // 2. If it does not exist, create the feed
    // 3. Register feed id in class
    // 4. Create empty buffer, output feed data is written at the end from this buffer string
    // ------------------------------------------------------------------------------
    public function output_feed($tagname) {

        $name = $tagname;    
        $tagname = explode(":",$tagname);
        
        if (!$id = $this->feed->exists_tag_name($this->userid,$tagname[0],$tagname[1])) {
            // Create feed if it doesnt exist
            $result = $this->feed->create($this->userid,$tagname[0],$tagname[1],1,5,(object)array("interval"=>$this->interval));
            if ($result["success"]) {
                $id = $result["feedid"];
            } else {
                echo $result["message"]."\n"; die;
            }
        }
    
        $this->output[$name] = $id;
        $this->buffer[$name] = "";
    }

    // ------------------------------------------------------------------------------
    // Basic read without interpolation
    // ------------------------------------------------------------------------------
    public function readp($name,$time) 
    {
        $fh = $this->fh[$name];
        $p = floor(($time - $this->m[$name]->start_time) / $this->m[$name]->interval);
        if ($p>=0 && $p<$this->m[$name]->npoints) {
            fseek($fh,$p*4);
            $tmp = @unpack("f",fread($fh,4));
            if (!is_nan($tmp[1])) return 1.0*$tmp[1];
        }
        return false;
    }

    // ------------------------------------------------------------------------------
    // Read value from input feed
    // 1. For speed only call seek if position is different from the last
    // 2. Read in both the current position and the next for linear interpolation
    // Linear interpolation is used to make it possible to use lower resolution feeds at higher resolution intervals
    // E.g you could use a half hourly temperature feed with a 10s solar feed.
    // 3. If smoothing is enabled use a digital lowpass filter to smooth the feed values
    // This is useful for making low resolution feeds appear higher resolution
    // which helps reduce step changes that lead to instable model results.    
    // ------------------------------------------------------------------------------
    public function read($name,$time) {
    
        $start_time = $this->m[$name]->start_time;
        $interval = $this->m[$name]->interval;
        $fh = $this->fh[$name];
        
        $lp = $this->p[$name];
        $p = floor(($time - $start_time) / $interval);
        $this->p[$name] = $p;
        
        $p_time = $start_time + ($p * $interval);
        $pD = ($time - $p_time) / $interval;
        
        if ($lp!=$p) {
            fseek($fh,$p*4);
            $tmp = @unpack("f",fread($fh,4));
            if (!is_nan($tmp[1])) $this->pA[$name] = 1.0*$tmp[1];
            
            // Linear interpolation
            $tmp = @unpack("f",fread($fh,4));
            if (!is_nan($tmp[1])) $this->pB[$name] = 1.0*$tmp[1];
        }
        
        // Linear interpolation
        $this->value[$name] = (($this->pB[$name]-$this->pA[$name])*$pD) + $this->pA[$name];
        
        // Smooth output, reduce step changes that lead to instable model
        if ($this->smooth[$name]>0) {
            $this->smooth_value[$name] += ($this->value[$name] - $this->smooth_value[$name]) * $this->smooth[$name];
            return $this->smooth_value[$name];
        } else {
            return $this->value[$name];
        }
    }

    // ------------------------------------------------------------------------------
    // Read running average
    // ------------------------------------------------------------------------------
    public function read_average($name,$time,$length) {
    
        $start_time = $this->m[$name]->start_time;
        $interval = $this->m[$name]->interval;
        $npoints = $this->m[$name]->npoints;
        $fh = $this->fh[$name];
        
        $lp = $this->p_av[$name];
        $p = floor(($time - $start_time) / $interval);
        $this->p_av[$name] = $p;

        /*
        $start = $time - $length;
        $end = $time + $length;
        $sum = 0; $n = 0;
        for ($time=$start; $time<$end; $time+=3600) {
            $p = floor(($time - $start_time) / $interval);
                if ($p>=0 && $p<$npoints) {
                fseek($fh,$p*4);
                $tmp = @unpack("f",fread($fh,4));
                if (!is_nan($tmp[1])) {
                    $sum += 1.0*$tmp[1];
                    $n++;
                }
            }
        }*/

        if ($lp!=$p) {
            
            $length = round($length/$interval);
            $p_pos = $p + $length;
            $p_neg = $p - $length;
            
            // Build initial sum at start
            if ($this->sum_count[$name]==0) {
                for ($p = $p_neg; $p<$p_pos; $p++) {
                    if ($p>=0 && $p<$npoints) {
                        fseek($fh,$p*4);
                        $tmp = @unpack("f",fread($fh,4));
                        if (!is_nan($tmp[1])) {
                            $this->sum[$name] += 1.0*$tmp[1];
                            $this->sum_count[$name]++;
                        }
                    }
                }
            }
           
            // add value to moving average
            if ($p_pos<$npoints) {
                fseek($fh,$p_pos*4);
                $tmp = @unpack("f",fread($fh,4));
                if (!is_nan($tmp[1])) {
                    $this->sum[$name] += 1.0*$tmp[1];
                    $this->sum_count[$name]++;
                }
            }
            
            // subtract value from moving average at end
            if ($p_neg>=0) {
                fseek($fh,$p_neg*4);
                $tmp = @unpack("f",fread($fh,4));
                if (!is_nan($tmp[1])) {
                    $this->sum[$name] -= 1.0*$tmp[1];
                    $this->sum_count[$name]--;
                }
            }
        }
        
        $average = 0;
        if ($this->sum_count[$name]>0) $average = $this->sum[$name] / $this->sum_count[$name];
        return $average;
        // return $sum / $n;
    }

    // ------------------------------------------------------------------------------

    public function write($name,$value) {
        $this->buffer[$name] .= pack("f",$value);
        $this->value[$name] = $value;
    }
    
    public function save_all() {
        $meta = new stdClass();
        $meta->start_time = $this->start_time;
        $meta->interval = $this->interval;
        
        foreach ($this->output as $name=>$feedid) {
            $this->write_output($feedid,$name,$meta,$this->buffer[$name]);
        }
        
        $this->feed->update_user_feeds_size($this->userid);
    }
    
    private function write_output($id,$name,$meta,$buffer) {
        // Overwrite existing meta data
        $metafile = fopen($this->dir.$id.".meta", 'wb');
        fwrite($metafile,pack("I",0));
        fwrite($metafile,pack("I",0)); 
        fwrite($metafile,pack("I",$meta->interval));
        fwrite($metafile,pack("I",$meta->start_time)); 
        fclose($metafile);

        // Write data to tmp file
        $fh = @fopen("/tmp/$id.dat", 'wb');
        fwrite($fh,$buffer); fclose($fh);
        
        $this->feed->set_timevalue($id, $this->value[$name], time());
        
        // Symlink to data location
        unlink($this->dir."$id.dat");
        symlink("/tmp/$id.dat",$this->dir.$id.".dat");
    }
}
