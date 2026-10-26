<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
    $source=dirname(__DIR__,2); require $source.'/backend/vendor/autoload.php';
    $testRoot=getenv('ACC_GROUPS_TEST_ROOT')?:'';
    if (!preg_match('~^'.preg_quote($source.'/work/access-groups-','~').'[a-f0-9]{12}$~D',$testRoot)) { exit(1); }
    $app=AccManager\App::boot($testRoot);
    $database=$app->db->one('SELECT DATABASE() n')['n'];
    if ($app->config['environment']!=='test'||!preg_match('/^acc_groups_[a-f0-9]{12}$/D',$database)) { exit(1); }
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $actor=$app->auth->user((int)$input['actor_id']);
    if (isset($input['barrier'])) {
        if (!preg_match('/^[a-f0-9]{12}$/D',$input['barrier'])) { exit(1); }
        $barrier=$app->root.'/storage/run/group-'.$input['barrier']; file_put_contents($barrier.'.ready','ready');
        $deadline=microtime(true)+5;
        while (!is_file($barrier.'.go')&&microtime(true)<$deadline) { usleep(1000); }
        if (!is_file($barrier.'.go')) { throw new RuntimeException('Barrier timeout'); }
    }
    if ($input['action']==='department') { $result=$app->people->departmentSave($input['data'],$actor); }
    else { $result=$app->accessGroups->assign($input['data'],$actor); }
    echo json_encode(['status'=>200,'id'=>$result['user']['id']??$result['id']??null],JSON_THROW_ON_ERROR);
} catch (AccManager\ApiError $e) { echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR); }
catch (Throwable $e) { echo json_encode(['status'=>500,'class'=>get_class($e),'line'=>$e->getLine()],JSON_THROW_ON_ERROR); exit(1); }
