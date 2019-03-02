<?php

// 1. Unzip dataset.zip to a folder called dataset
// 2. Run this script to import the datasets into your local emoncms installation

$dir = getcwd();

$userid = 1;
$emoncms_dir = "/var/www/emoncms/";
$files = scandir("dataset");

require "ModelHelper.php";
$data = new ModelHelper($emoncms_dir,false,false);

foreach ($files as $file) {
    if (strpos($file,".meta")>0) {
        $dataset = str_replace(".meta","",$file);
        
        $result = $data->feed->create($userid,"model",$dataset,1,5,json_decode('{"interval":10}'));

        print json_encode($result)."\n";

        if ($result["success"]) {
            $feedid = $result["feedid"];
            
            print "copy $dir/dataset/$dataset.meta /var/lib/phpfina/$feedid.meta\n";
            copy("$dir/dataset/$dataset.meta","/var/lib/phpfina/$feedid.meta");
            
            print "copy $dir/dataset/$dataset.meta /var/lib/phpfina/$feedid.meta\n";
            copy("$dir/dataset/$dataset.dat","/var/lib/phpfina/$feedid.dat");
        }
    }
}



