<?php

use FastComposer\InstallCompatibility;
use FastComposer\InProcessComposer;

class InstallCompatibilityFixture
{
    public function matching(string $directory): bool
    {
        return true;
    }

    final public function finalMethod(string $directory): bool
    {
        return true;
    }

    public function changed(int $directory): bool
    {
        return true;
    }
}

fc_assert(InstallCompatibility::canOverride(new ReflectionMethod(InstallCompatibilityFixture::class, 'matching'), ['string'], 'bool'), 'Matching signature');
fc_assert(!InstallCompatibility::canOverride(new ReflectionMethod(InstallCompatibilityFixture::class, 'finalMethod'), ['string'], 'bool'), 'Final override rejected');
fc_assert(!InstallCompatibility::canOverride(new ReflectionMethod(InstallCompatibilityFixture::class, 'changed'), ['string'], 'bool'), 'Changed parameter rejected');
fc_assert(!InstallCompatibility::callableMethod(InstallCompatibilityFixture::class, 'missing', 0), 'Missing method rejected');
fc_assert(!InstallCompatibility::callableMethod(InstallCompatibilityFixture::class, 'matching', 0), 'Wrong arity rejected');
if (InProcessComposer::loadClasses() && InstallCompatibility::supportsApplication()) {
    fc_assert(class_exists(\FastComposer\AcceleratedComposerApplication::class), 'Application can be declared safely');
}
