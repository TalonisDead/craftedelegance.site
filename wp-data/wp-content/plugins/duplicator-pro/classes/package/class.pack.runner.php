<?php

/*
  Duplicator Pro Plugin
  Copyright (C) 2016, Snap Creek LLC
  website: snapcreek.com

  Duplicator Pro Plugin is distributed under the GNU General Public License, Version 3,
  June 2007. Copyright (C) 2007 Free Software Foundation, Inc., 51 Franklin
  St, Fifth Floor, Boston, MA 02110, USA

  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
  ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
  WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
  DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
  ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
  (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
  LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
  ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
  (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
  SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

defined('ABSPATH') || defined('DUPXABSPATH') || exit;

use Duplicator\Addons\ProBase\License\License;
use Duplicator\Core\Controllers\ControllersManager;
use Duplicator\Libs\Snap\SnapURL;
use Duplicator\Libs\Snap\SnapUtil;
use Duplicator\Models\Storages\StoragesUtil;
use Duplicator\Models\SystemGlobalEntity;
use Duplicator\Utils\LockUtil;

class DUP_PRO_Package_Runner
{
    const DEFAULT_MAX_BUILD_TIME_IN_MIN = 270;
    const PACKAGE_STUCK_TIME_IN_SEC     = 375; // 75 x 5;

    /** @var bool */
    public static $delayed_exit_and_kickoff = false;

    /**
     * Init Backup runner
     *
     * @return void
     * @throws Exception
     */
    public static function init()
    {
        $kick_off_worker = false;
        $global          = DUP_PRO_Global_Entity::getInstance();
        $system_global   = SystemGlobalEntity::getInstance();

        if ($global->clientside_kickoff === false) {
            if ((time() - $system_global->package_check_ts) < DUP_PRO_Constants::PACKAGE_CHECK_TIME_IN_SEC) {
                return;
            }
        }

        DUP_PRO_Log::trace('Running Backup runner init');

        if (!LockUtil::lockProcess()) {
            return;
        }

        DUP_PRO_Log::trace("Acquired lock so executing Backup runner init core code");
        $system_global->package_check_ts = time();
        $system_global->save();

        $pending_cancellations = DUP_PRO_Package::get_pending_cancellations();

        self::cancel_long_running($pending_cancellations);

        if (count($pending_cancellations) > 0) {
            foreach ($pending_cancellations as $package_id_to_cancel) {
                DUP_PRO_Log::trace("looking to cancel $package_id_to_cancel");
                $package_to_cancel = DUP_PRO_Package::get_by_id((int) $package_id_to_cancel);
                if ($package_to_cancel == false) {
                    continue;
                }

                if ($package_to_cancel->Status == DUP_PRO_PackageStatus::STORAGE_PROCESSING) {
                    $isDownloadInProgress = $package_to_cancel->isDownloadInProgress();
                    $lastInfo             = end($package_to_cancel->upload_infos);
                    $lastIsDownload       = $lastInfo->isDownloadFromRemote();

                    $package_to_cancel->cancel_all_uploads();
                    $package_to_cancel->process_storages();

                    if (!$isDownloadInProgress && !$lastIsDownload) {
                        $package_to_cancel->set_status(DUP_PRO_PackageStatus::STORAGE_CANCELLED);
                    } else {
                        DUP_PRO_Package::delete_default_local_files($package_to_cancel->getNameHash(), true, false);
                        $package_to_cancel->set_status(DUP_PRO_PackageStatus::COMPLETE);
                    }
                } else {
                    $package_to_cancel->set_status(DUP_PRO_PackageStatus::BUILD_CANCELLED);
                }

                $package_to_cancel->post_scheduled_build_failure();
            }

            DUP_PRO_Package::clear_pending_cancellations();
        }

        $action = ControllersManager::getInstance()->getAction();
        if ($action === false || $action !== 'duplicator_pro_process_worker') {
            self::process_schedules();
            $kick_off_worker = DUP_PRO_Package::isPackageRunning();
        }

        LockUtil::unlockProcess();

        if ($kick_off_worker || self::$delayed_exit_and_kickoff) {
            self::kick_off_worker();
        } elseif (is_admin() && ControllersManager::getInstance()->isDuplicatorPage()) {
            DUP_PRO_Log::trace("************kicking off slug worker");
            // If it's one of our pages force it to kick off the client
            self::kick_off_worker(true);
        }

        if (self::$delayed_exit_and_kickoff) {
            self::$delayed_exit_and_kickoff = false;
            exit();
        }
    }

    /**
     * Add javascript for cliean side Kick off
     *
     * @return void
     */
    public static function add_kickoff_worker_javascript()
    {
        $global                   = DUP_PRO_Global_Entity::getInstance();
        $custom_url               = strtolower($global->custom_ajax_url);
        $CLIENT_CALL_PERIOD_IN_MS = 20000;
        // How often client calls into the service

        if ($global->ajax_protocol == 'custom') {
            if (DUP_PRO_STR::startsWith($custom_url, 'http')) {
                $ajax_url = $custom_url;
            } else {
                // Revert to http standard if they don't have the url correct
                $ajax_url = admin_url('admin-ajax.php', 'http');
                DUP_PRO_Log::trace("Even though custom ajax url configured, incorrect url set so reverting to $ajax_url");
            }
        } else {
            $ajax_url = admin_url('admin-ajax.php', $global->ajax_protocol);
        }

        $gateway = [
            'ajaxurl'                             => $ajax_url,
            'client_call_frequency'               => $CLIENT_CALL_PERIOD_IN_MS,
            'duplicator_pro_process_worker_nonce' => wp_create_nonce('duplicator_pro_process_worker'),
        ];
        wp_register_script('dup-pro-kick', DUPLICATOR_PRO_PLUGIN_URL . 'assets/js/dp-kick.js', ['jquery'], DUPLICATOR_PRO_VERSION);
        wp_localize_script('dup-pro-kick', 'dp_gateway', $gateway);
        DUP_PRO_Log::trace('KICKOFF: Client Side');
        wp_enqueue_script('dup-pro-kick');
    }

    /**
     * Checks active Backups for being stuck or running too long and adds them for canceling
     *
     * @param int[] $pending_cancellations List of Backup ids to be cancelled
     *
     * @return void
     */
    protected static function cancel_long_running(&$pending_cancellations)
    {
        if (!DUP_PRO_Package::isPackageRunning()) {
            return;
        }

        $active_package = DUP_PRO_Package::get_next_active_package();
        if ($active_package === null) {
            DUP_PRO_Log::trace("Active Backup returned null");
            return;
        }

        self::cancel_max_build_time_reached($pending_cancellations, $active_package);
        self::cancel_max_transfer_time_reached($pending_cancellations, $active_package);
    }

    /**
     * Checks if the active Backup has been building for too long and adds it for cancelling
     *
     * @param int[]           $pending_cancellations List of Backup ids to be cancelled
     * @param DUP_PRO_Package $active_package        The active Backup
     *
     * @return void
     */
    protected static function cancel_max_build_time_reached(&$pending_cancellations, $active_package)
    {
        if ($active_package->Status == DUP_PRO_PackageStatus::STORAGE_PROCESSING) {
            return;
        }

        $global                      = DUP_PRO_Global_Entity::getInstance();
        $system_global               = SystemGlobalEntity::getInstance();
        $buildStarted                = $active_package->timer_start > 0;
        $active_package->timer_start = $buildStarted ? $active_package->timer_start : DUP_PRO_U::getMicrotime();
        $elapsed_sec                 = $buildStarted ? DUP_PRO_U::getMicrotime() - $active_package->timer_start : 0;
        $elapsed_minutes             = $elapsed_sec / 60;
        $addedForCancelling          = false;

        // If build has started & we are not uploading yet, we will consider the max build time.
        if ($global->max_package_runtime_in_min > 0 && $elapsed_minutes > $global->max_package_runtime_in_min) {
            if ($active_package->build_progress->current_build_mode != DUP_PRO_Archive_Build_Mode::DupArchive) {
                $system_global->addQuickFix(
                    __('Backup was cancelled because it exceeded Max Build Time.', 'duplicator-pro'),
                    sprintf(
                        __(
                            'Click button to switch to the DupArchive engine. Please see this %1$sFAQ%2$s for other possible solutions.',
                            'duplicator-pro'
                        ),
                        '<a href="' . DUPLICATOR_PRO_DUPLICATOR_DOCS_URL . 'how-to-resolve-schedule-build-failures" target="_blank">',
                        '</a>'
                    ),
                    [
                        'global' => [
                            'archive_build_mode' => DUP_PRO_Archive_Build_Mode::DupArchive,
                        ],
                    ]
                );
            } elseif ($global->max_package_runtime_in_min < self::DEFAULT_MAX_BUILD_TIME_IN_MIN) {
                $system_global->addQuickFix(
                    __('Backup was cancelled because it exceeded Max Build Time.', 'duplicator-pro'),
                    sprintf(
                        __(
                            'Click button to increase Max Build Time. Please see this %1$sFAQ%2$s for other possible solutions.',
                            'duplicator-pro'
                        ),
                        '<a href="' . DUPLICATOR_PRO_DUPLICATOR_DOCS_URL . 'how-to-resolve-schedule-build-failures" target="_blank">',
                        '</a>'
                    ),
                    [
                        'global' => [
                            'max_package_runtime_in_min' => self::DEFAULT_MAX_BUILD_TIME_IN_MIN,
                        ],
                    ]
                );
            }

            DUP_PRO_Log::infoTrace("Package $active_package->ID has been going for $elapsed_minutes minutes so cancelling. ($elapsed_sec)");
            array_push($pending_cancellations, $active_package->ID);
            $addedForCancelling = true;
        }

        if ((($active_package->Status == DUP_PRO_PackageStatus::AFTER_SCAN) || ($active_package->Status == DUP_PRO_PackageStatus::PRE_PROCESS)) && ($global->clientside_kickoff == false)) {
            // Traditionally Backup considered stuck if > 75 but that was with time % 5 so multiplying by 5 to compensate now
            if ($elapsed_sec > self::PACKAGE_STUCK_TIME_IN_SEC) {
                DUP_PRO_Log::trace("*** STUCK");
                $showDefault = true;
                if (isset($_SERVER['AUTH_TYPE']) && $_SERVER['AUTH_TYPE'] == 'Basic' && !$global->basic_auth_enabled) {
                    $system_global->addQuickFix(
                        __('Set authentication username and password', 'duplicator-pro'),
                        __('Automatically set basic auth username and password', 'duplicator-pro'),
                        [
                            'special' => ['set_basic_auth' => 1],
                        ]
                    );
                    $showDefault = false;
                }

                if (SnapURL::isCurrentUrlSSL() && $global->ajax_protocol == 'http') {
                    $system_global->addQuickFix(
                        __('Communication to AJAX is blocked.', 'duplicator-pro'),
                        __('Click button to configure plugin to use HTTPS.', 'duplicator-pro'),
                        [
                            'special' => ['stuck_5percent_pending_fix' => 1],
                        ]
                    );
                } elseif (!SnapURL::isCurrentUrlSSL() && $global->ajax_protocol == 'https') {
                    $system_global->addQuickFix(
                        __('Communication to AJAX is blocked.', 'duplicator-pro'),
                        __('Click button to configure plugin to use HTTP.', 'duplicator-pro'),
                        [
                            'special' => ['stuck_5percent_pending_fix' => 1],
                        ]
                    );
                } elseif ($global->ajax_protocol == 'custom') {
                    $system_global->addQuickFix(
                        __('Communication to AJAX is blocked.', 'duplicator-pro'),
                        __('Click button to fix the admin-ajax URL setting.', 'duplicator-pro'),
                        [
                            'special' => ['stuck_5percent_pending_fix' => 1],
                        ]
                    );
                } elseif ($showDefault) {
                    $system_global->addTextFix(
                        __('Communication to AJAX is blocked.', 'duplicator-pro'),
                        sprintf(
                            "%s <a href='" . DUPLICATOR_PRO_DUPLICATOR_DOCS_URL . "how-to-resolve-builds-getting-stuck-at-a-certain-point/' target='_blank'>%s</a>",
                            __('See FAQ:', 'duplicator-pro'),
                            __('Why is the Backup build stuck at 5%?', 'duplicator-pro')
                        )
                    );
                }

                DUP_PRO_Log::infoTrace("Package $active_package->ID has been stuck for $elapsed_minutes minutes so cancelling. ($elapsed_sec)");
                array_push($pending_cancellations, $active_package->ID);
                $addedForCancelling = true;
            }
        }

        if ($addedForCancelling) {
            $active_package->buildFail(
                'Backup was cancelled because it exceeded Max Build Time.',
                false
            );
        } else {
            $active_package->save();
        }
    }

    /**
     * Checks if the active Backup has been transferring for too long and adds it for cancelling
     *
     * @param int[]           $pending_cancellations List of Backup ids to be cancelled
     * @param DUP_PRO_Package $active_package        The active Backup
     *
     * @return void
     */
    protected static function cancel_max_transfer_time_reached(&$pending_cancellations, $active_package)
    {
        if ($active_package->Status != DUP_PRO_PackageStatus::STORAGE_PROCESSING) {
            return;
        }

        $latestInfos = $active_package->get_latest_upload_infos();
        if (empty($latestInfos[$active_package->active_storage_id])) {
            return;
        }
        $uploadInfo = $latestInfos[$active_package->active_storage_id];
        if ($uploadInfo->has_completed()) {
            return;
        }

        // We consider the Backup is in "uploading state" if it's in STORAGE_PROCESSING status and has more than one upload_infos (i.e. the default storage processing done)
        $global               = DUP_PRO_Global_Entity::getInstance();
        $system_global        = SystemGlobalEntity::getInstance();
        $uploadStartedAt      = $uploadInfo->started_timestamp;
        $uploadStartedAt      = $uploadStartedAt > 0 ? $uploadStartedAt : DUP_PRO_U::getMicrotime();
        $uploadElapsedSec     = DUP_PRO_U::getMicrotime() - $uploadStartedAt;
        $uploadElapsedMinutes = $uploadElapsedSec / 60;

        // If we are uploading the Backup, we consider the max Backup transfer time.
        if ($global->max_package_transfer_time_in_min < $uploadElapsedMinutes) {
            $system_global->addQuickFix(
                __('Backup transfer was cancelled because it exceeded Max Transfer Time.', 'duplicator-pro'),
                sprintf(
                    __(
                        'Click button to increase Max Transfer Time. Please see this %1$sFAQ%2$s for other possible solutions.',
                        'duplicator-pro'
                    ),
                    '<a href="' . DUPLICATOR_PRO_DUPLICATOR_DOCS_URL . 'how-to-resolve-schedule-build-failures" target="_blank">',
                    '</a>'
                ),
                [
                    'global' => [
                        'max_package_transfer_time_in_min' => self::DEFAULT_MAX_BUILD_TIME_IN_MIN,
                    ],
                ]
            );

            DUP_PRO_Log::infoTrace("Package $active_package->ID has been transferring for $uploadElapsedMinutes minutes so cancelling. ($uploadElapsedSec)");
            array_push($pending_cancellations, $active_package->ID);
        }
    }

    /**
     * Kick off worker
     *
     * @param bool $run_only_if_client If true then only kick off worker if the request came from the client
     *
     * @return void
     */
    public static function kick_off_worker($run_only_if_client = false)
    {
        $global = DUP_PRO_Global_Entity::getInstance();
        if (!$run_only_if_client || $global->clientside_kickoff) {
            $calling_function_name = SnapUtil::getCallingFunctionName();
            DUP_PRO_Log::trace("Kicking off worker process as requested by $calling_function_name");
            $custom_url = strtolower($global->custom_ajax_url);
            if ($global->ajax_protocol == 'custom') {
                if (DUP_PRO_STR::startsWith($custom_url, 'http')) {
                    $ajax_url = $custom_url;
                } else {
                    // Revert to http standard if they don't have the url correct
                    $ajax_url = admin_url('admin-ajax.php', 'http');
                    DUP_PRO_Log::trace("Even though custom ajax url configured, incorrect url set so reverting to $ajax_url");
                }
            } else {
                $ajax_url = admin_url('admin-ajax.php', $global->ajax_protocol);
            }

            DUP_PRO_Log::trace("Attempting to use ajax url $ajax_url");
            if ($global->clientside_kickoff) {
                add_action('wp_enqueue_scripts', 'DUP_PRO_Package_Runner::add_kickoff_worker_javascript');
                add_action('admin_enqueue_scripts', 'DUP_PRO_Package_Runner::add_kickoff_worker_javascript');
            } else {
                // Server-side kickoff
                $ajax_url = SnapURL::appendQueryValue($ajax_url, 'action', 'duplicator_pro_process_worker');
                $ajax_url = SnapURL::appendQueryValue($ajax_url, 'now', time());
                // $duplicator_pro_process_worker_nonce = wp_create_nonce('duplicator_pro_process_worker');
                //require_once(ABSPATH.'wp-includes/pluggable.php');
                //$ajax_url = wp_nonce_url($ajax_url, 'duplicator_pro_process_worker', 'nonce');

                DUP_PRO_Log::trace('KICKOFF: Server Side');
                if ($global->basic_auth_enabled) {
                    $sglobal = DUP_PRO_Secure_Global_Entity::getInstance();
                    $args    = [
                        'blocking' => false,
                        'headers'  => ['Authorization' => 'Basic ' . base64_encode($global->basic_auth_user . ':' . $sglobal->basic_auth_password)],
                    ];
                } else {
                    $args = ['blocking' => false];
                }
                $args['sslverify'] = false;
                wp_remote_get($ajax_url, $args);
            }

            DUP_PRO_Log::trace("after sent kickoff request");
        }
    }

    /**
     * Process schedules by cron
     *
     * @return void
     */
    public static function process()
    {
        if (!defined('WP_MAX_MEMORY_LIMIT')) {
            define('WP_MAX_MEMORY_LIMIT', '512M');
        }

        if (SnapUtil::isIniValChangeable('memory_limit')) {
            @ini_set('memory_limit', WP_MAX_MEMORY_LIMIT);
        }

        @set_time_limit(7200);
        SnapUtil::ignoreUserAbort(true);

        if (SnapUtil::isIniValChangeable('pcre.backtrack_limit')) {
            @ini_set('pcre.backtrack_limit', (string) PHP_INT_MAX);
        }

        if (SnapUtil::isIniValChangeable('default_socket_timeout')) {
            @ini_set('default_socket_timeout', '7200');
            // 2 Hours
        }

        /* @var $global DUP_PRO_Global_Entity */
        $global = DUP_PRO_Global_Entity::getInstance();
        if ($global->clientside_kickoff) {
            DUP_PRO_Log::trace("PROCESS: From client");
            session_write_close();
        } else {
            DUP_PRO_Log::trace("PROCESS: From server");
        }

        if (!LockUtil::lockProcess()) {
            // File locked so another cron already running so just skip
            DUP_PRO_Log::trace("Process locked so skipping");
            return;
        }

        // Here we know that $acquired_lock == true
        self::process_schedules();
        $package = DUP_PRO_Package::get_next_active_package();

        if ($package != null) {
            StoragesUtil::getDefaultStorage()->initStorageDirectory(true);
            $dup_tests = self::get_requirements_tests();
            if ($dup_tests['Success'] == true) {
                $start_time = time();
                DUP_PRO_Log::trace("PACKAGE $package->ID:PROCESSING. STATUS: $package->Status");
                SnapUtil::ignoreUserAbort(true);
                if ($package->Status <= DUP_PRO_PackageStatus::SCANNING) {
                    // Scan step built into Backup build - used by schedules - NOT manual build where scan is done in web service.
                    DUP_PRO_Log::trace("PACKAGE $package->ID:SCANNING");
                    $fileScanDone = false;
                    if ($package->Status < DUP_PRO_PackageStatus::SCANNING) {
                        DUP_PRO_Log::trace("PACKAGE $package->ID: SCAN FIRST CHUNK");
                        $fileScanDone = $package->Archive->scanFiles(true);
                        $package->set_status(DUP_PRO_PackageStatus::SCANNING);
                    } else {
                        DUP_PRO_Log::trace("PACKAGE $package->ID: CONTINUE SCAN");
                        $fileScanDone = $package->Archive->scanFiles();
                    }

                    if ($fileScanDone) {
                        DUP_PRO_Log::trace("PACKAGE $package->ID: SCAN COMPLETE. NEED TO VALIDATE");
                        $package->set_status(DUP_PRO_PackageStatus::SCAN_VALIDATION);
                    }

                    $scan_time = time() - $start_time;
                    DUP_PRO_Log::trace("SCAN CHUNK TIME=$scan_time seconds");
                } elseif ($package->Status <= DUP_PRO_PackageStatus::SCAN_VALIDATION) {
                    //After scanner runs validate the index file
                    DUP_PRO_Log::trace("PACKAGE $package->ID: SCAN VALIDATION");
                    if ($package->Archive->validateIndexFile()) {
                        DUP_PRO_Log::trace("PACKAGE $package->ID: SCAN VALIDATION PASSED");
                        $package->createScanReport();
                        $package->set_status(DUP_PRO_PackageStatus::AFTER_SCAN);
                    } else {
                        DUP_PRO_Log::infoTrace("PACKAGE $package->ID:SCAN VALIDATION FAILED");
                        $package->set_status(DUP_PRO_PackageStatus::ERROR);
                    }

                    // Save the package after each scan chunk
                    $package->update();

                    $scan_time = time() - $start_time;
                    DUP_PRO_Log::trace("SCAN VALIDATION TIME=$scan_time seconds");
                } elseif ($package->Status < DUP_PRO_PackageStatus::COPIEDPACKAGE) {
                    DUP_PRO_Log::trace("PACKAGE $package->ID:BUILDING");
                    $package->run_build();
                    $end_time   = time();
                    $build_time = $end_time - $start_time;
                    DUP_PRO_Log::trace("BUILD TIME=$build_time seconds");
                } elseif ($package->Status < DUP_PRO_PackageStatus::COMPLETE) {
                    DUP_PRO_Log::trace("PACKAGE $package->ID:STORAGE PROCESSING");
                    $package->set_status(DUP_PRO_PackageStatus::STORAGE_PROCESSING);
                    $package->process_storages();
                    $end_time   = time();
                    $build_time = $end_time - $start_time;
                    DUP_PRO_Log::trace("STORAGE CHUNK PROCESSING TIME=$build_time seconds");
                    if ($package->Status == DUP_PRO_PackageStatus::COMPLETE) {
                        DUP_PRO_Log::trace("PACKAGE $package->ID COMPLETE");
                    } elseif ($package->Status == DUP_PRO_PackageStatus::ERROR) {
                        DUP_PRO_Log::trace("PACKAGE $package->ID IN ERROR STATE");
                    }

                    $packageCompleteStatuses = [
                        DUP_PRO_PackageStatus::COMPLETE,
                        DUP_PRO_PackageStatus::ERROR,
                    ];
                    if (in_array($package->Status, $packageCompleteStatuses)) {
                        $info  = "\n";
                        $info .= "********************************************************************************\n";
                        $info .= "********************************************************************************\n";
                        $info .= "DUPLICATOR PRO PACKAGE CREATION OR MANUAL STORAGE TRANSFER END: " . @date("Y-m-d H:i:s") . "\n";
                        $info .= "NOTICE: Do NOT post to public sites or forums \n";
                        $info .= "********************************************************************************\n";
                        $info .= "********************************************************************************\n";
                        DUP_PRO_Log::infoTrace($info);
                    }
                }

                SnapUtil::ignoreUserAbort(false);
            } else {
                DUP_PRO_Log::open($package->getNameHash());

                if ($dup_tests['RES']['INSTALL'] == 'Fail') {
                    DUP_PRO_Log::info('Installer files still present on site. Remove using Tools > Stored Data > "Remove Installer Files".');
                }

                DUP_PRO_Log::error(__('Requirements Failed', 'duplicator-pro'), print_r($dup_tests, true), false);
                DUP_PRO_Log::traceError('Requirements didn\'t pass so can\'t perform backup!');
                $package->post_scheduled_build_failure($dup_tests);
                $package->set_status(DUP_PRO_PackageStatus::REQUIREMENTS_FAILED);
            }

            // Free index manager file lock
            $package->Archive->freeIndexManager();
        }

        //$kick_off_worker = (DUP_PRO_Package::get_next_active_package() != null);
        $kick_off_worker = DUP_PRO_Package::isPackageRunning();

        LockUtil::unlockProcess();

        if ($kick_off_worker) {
            self::kick_off_worker();
        }
    }

    /**
     * Gets the requirements tests
     *
     * @return array<string,mixed>
     */
    public static function get_requirements_tests(): array
    {
        $dup_tests = DUP_PRO_Server::getRequirments();
        if ($dup_tests['Success'] != true) {
            DUP_PRO_Log::traceObject('requirements', $dup_tests);
        }

        return $dup_tests;
    }

    /**
     * Calculates the earliest schedule run time
     *
     * @return int
     */
    public static function calculate_earliest_schedule_run_time()
    {
        $next_run_time = PHP_INT_MAX;
        $schedules     = DUP_PRO_Schedule_Entity::get_active();
        foreach ($schedules as $schedule) {
            if ($schedule->next_run_time == -1) {
                $schedule->updateNextRuntime();
            }

            if ($schedule->next_run_time !== -1 && $schedule->next_run_time < $next_run_time) {
                $next_run_time = $schedule->next_run_time;
            }
        }

        if ($next_run_time == PHP_INT_MAX) {
            $next_run_time = -1;
        }

        return $next_run_time;
    }

    /**
     * Start schedule Backup creation
     *
     * @return void
     */
    public static function process_schedules()
    {
        // Hack fix - observed issue on a machine where schedule process bombs
        $next_run_time = self::calculate_earliest_schedule_run_time();
        if ($next_run_time != -1 && ($next_run_time <= time())) {
            $schedules = DUP_PRO_Schedule_Entity::get_active();
            foreach ($schedules as $schedule) {
                $schedule->process();
            }
        }
    }
}
