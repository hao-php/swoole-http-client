<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Internal;

/** 区分正文未设置与显式 JSON null，调用方通常无需直接使用。 @internal */
enum UnsetValue
{
    case Value;
}
