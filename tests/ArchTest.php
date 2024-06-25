<?php

declare(strict_types=1);

arch('It will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('All source files are strictly typed')
    ->expect('VendorName\\Skeleton\\')
    ->toUseStrictTypes();

arch('All tests files are strictly typed')
    ->expect('VendorName\\Skeleton\\Tests\\')
    ->toUseStrictTypes();
