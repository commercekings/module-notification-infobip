<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Exception;

/**
 * Infobip refused a message: nothing was sent. Carries Infobip's status or error name (e.g. REJECTED_DESTINATION) and
 * the HTTP status (getCode()).
 */
class InfobipException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $infobipName = '', int $httpStatus = 0,
        ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
    }

    /** Infobip's status or error name, upper case; '' when the answer had none. */
    public function getInfobipName(): string
    {
        return $this->infobipName;
    }
}
