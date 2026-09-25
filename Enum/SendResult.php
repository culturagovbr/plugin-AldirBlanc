<?php

namespace AldirBlanc\Enum;

enum SendResult: string
{
    case Success = 'success';
    case Error = 'error';
    case Simulated = 'simulated';
}
