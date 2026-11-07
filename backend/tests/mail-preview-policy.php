<?php
declare(strict_types=1);
require __DIR__.'/../src/Mailer.php';
use Zenith\Mailer;
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$c=['preview_enabled'=>true,'preview_operator_email'=>'owner.test@gmail.com'];
check(Mailer::recipientAllowed($c,'owner.test@gmail.com'),'Owner recipient allowed');
check(Mailer::recipientAllowed($c,'owner.test+preview@gmail.com'),'Owner Gmail plus alias allowed');
check(!Mailer::recipientAllowed($c,'other@gmail.com'),'Unrelated Gmail recipient blocked');
check(!Mailer::recipientAllowed($c,'owner.test+preview@example.com'),'Other domain blocked');
check(!Mailer::recipientAllowed($c,'other+owner.test@gmail.com'),'Misleading local part blocked');
check(!Mailer::recipientAllowed(['preview_enabled'=>true],'owner.test@gmail.com'),'Missing owner policy fails closed');
check(Mailer::recipientAllowed([],'customer@example.com'),'Production mail behavior unchanged');
check(!Mailer::recipientAllowed(['preview_enabled'=>true,'preview_operator_email'=>'owner@example.com'],'owner+other@example.com'),'Plus aliases not assumed for other domains');
check(!Mailer::recipientAllowed($c,"owner.test@gmail.com\r\nInjected"),'Invalid address blocked');
echo 'PASS '.$n." preview mail recipient policy assertions.\n";
