<?php

use FastComposer\InstallCompatibility;

foreach (['2.8.12', '2.8.13', '2.9.0', '2.9.5', '2.10.3', '2.10.10'] as $version) {
    fc_assert(InstallCompatibility::supports($version), 'Supported: '.$version);
}
foreach (['2.8.11', '2.7.9', '2.3.10', '2.11.0', '3.0.0', '2.10.3-dev', '2.10.3-RC1'] as $version) {
    fc_assert(!InstallCompatibility::supports($version), 'Fallback: '.$version);
}
