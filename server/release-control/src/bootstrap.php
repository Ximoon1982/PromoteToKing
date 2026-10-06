<?php
declare(strict_types=1);

define('P2K_RELEASE_CONTROL_ROOT', dirname(__DIR__, 3));

require_once __DIR__ . '/ReleaseControlAuth.php';
require_once __DIR__ . '/ReleaseSlotPolicy.php';
require_once __DIR__ . '/ReleaseSlotFilesystemProbe.php';
require_once __DIR__ . '/ReleaseSlotStore.php';
require_once __DIR__ . '/ReleaseSlotMaterializer.php';
require_once __DIR__ . '/ReleaseCandidatePackage.php';
require_once __DIR__ . '/ReleaseStateStore.php';
require_once __DIR__ . '/ReleaseRuntimeTree.php';
require_once __DIR__ . '/ReleaseDeploymentManager.php';
require_once __DIR__ . '/ReleaseCandidateInstaller.php';
require_once __DIR__ . '/ReleasePreviewTree.php';
require_once __DIR__ . '/ReleasePreviewSession.php';
require_once __DIR__ . '/ReleaseControlState.php';
