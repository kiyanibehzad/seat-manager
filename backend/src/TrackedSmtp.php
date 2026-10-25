<?php
declare(strict_types=1);
namespace AccManager;
final class TrackedSmtp extends \PHPMailer\PHPMailer\SMTP
{
    public bool $dataAttempted=false;
    public function data($msg_data)
    {
        // From this point a lost SMTP acknowledgement can mean delivery already occurred.
        $this->dataAttempted=true;
        return parent::data($msg_data);
    }
}
