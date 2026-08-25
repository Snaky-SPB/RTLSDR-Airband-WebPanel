<?php

declare(strict_types=1);

namespace AirbandWebPanel\Exceptions;

/**
 * Ошибка записи на диск (нет места, права, read-only fs и т.п.)
 */
class WriteException extends \RuntimeException
{
}
