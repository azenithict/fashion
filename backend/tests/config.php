<?php
declare(strict_types=1);
// Isolated test fixtures only; never use this file on production hosting.
$c=require __DIR__.'/../config.example.php';
$c['environment']='test';$c['public_origin']='http://127.0.0.1:8080';
$c['db']=['dsn'=>'mysql:host=127.0.0.1;port=3306;dbname=zenith_test;charset=utf8mb4','user'=>'zenith_test','password'=>'test-only-ci-password'];
$c['storage_path']=sys_get_temp_dir().'/zenith-ci/storage';$c['session_path']=sys_get_temp_dir().'/zenith-ci/sessions';
$c['lock_path']=sys_get_temp_dir().'/zenith-ci/application.lock';$c['session_name']='zenith_ci';$c['session_secure']=false;
$c['manual_payments_enabled']=true;$c['manual_payment_approval_reference']='TEST ONLY';
$c['pricing_min_margin_bps']=2000;$c['pricing_approval_reference']='TEST ONLY';
$c['merchant']=['legal_name'=>'TEST ONLY merchant','address'=>'TEST ONLY invoice address','tax_registration'=>'TEST ONLY tax registration','invoice_approval_reference'=>'TEST ONLY legal/tax approval'];
return $c;
