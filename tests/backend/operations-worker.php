<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
 $root=dirname(__DIR__,2); require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root); $app=require $root.'/backend/bootstrap.php';
 testDatabaseGuard($app);
 $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); $actor=$app->auth->user((int)$input['actor']);
 $barrier=null;
 if (isset($input['barrier'])&&is_string($input['barrier'])&&preg_match('/^capacity-race-[a-f0-9]{12}$/D',$input['barrier'])) {
  $barrier=$root.'/storage/run/'.$input['barrier']; file_put_contents($barrier.'.ready','ready'); $deadline=microtime(true)+5;
  while (!is_file($barrier.'.release')&&microtime(true)<$deadline) { usleep(1000); }
  if (!is_file($barrier.'.release')) { throw new RuntimeException('test barrier timeout'); }
 }
 $engine=new AccManager\Operations\Engine(new AccManager\Operations\Domain($app));
 if ($input['action']==='result') { $result=$engine->result($input['data'],$actor); } else { $result=$engine->startDirect($input['data'],$actor); }
 echo json_encode(['status'=>200,'id'=>$result['operation']['id']],JSON_THROW_ON_ERROR);
} catch (AccManager\ApiError $e) { echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR); }
catch (Throwable $e) { echo json_encode(['status'=>500,'type'=>get_class($e),'line'=>$e->getLine()],JSON_THROW_ON_ERROR); exit(1); }

finally { if (isset($barrier)&&$barrier!==null) { file_put_contents($barrier.'.done','done'); } }
