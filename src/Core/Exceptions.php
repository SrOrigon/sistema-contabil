<?php

declare(strict_types=1);

namespace App\Core;

class AppException extends \RuntimeException
{
}

class InvalidXmlException extends AppException
{
}

class FiscalRuleMismatchException extends AppException
{
}

class NcmNotFoundException extends AppException
{
}

class SimplesLimitExceededException extends AppException
{
}

class ValidationException extends AppException
{
}

class ReportBlockedException extends AppException
{
}
