<?php
declare(strict_types=1);
require __DIR__.'/../src/Kernel.php';
use Zenith\Kernel;
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;}
check(Kernel::baseUrl(['public_origin'=>'https://fashion.azenith.ca'])==='https://fashion.azenith.ca','Production URL unchanged');
check(Kernel::baseUrl(['public_origin'=>'https://fashion.azenith.ca/','public_base_path'=>'/_fashion-preview'])==='https://fashion.azenith.ca/_fashion-preview','Preview prefix appended to same origin');
foreach([['public_origin'=>'https://user@fashion.azenith.ca'],['public_origin'=>'https://fashion.azenith.ca/path'],['public_origin'=>'https://fashion.azenith.ca','public_base_path'=>'//other.example'],['public_origin'=>'https://fashion.azenith.ca','public_base_path'=>'/../other'],['public_origin'=>'javascript:alert(1)']] as $config){try{Kernel::baseUrl($config);check(false,'Unsafe public URL accepted');}catch(RuntimeException){check(true,'Unsafe public URL rejected');}}
echo 'PASS '.$n." canonical public URL assertions.\n";
