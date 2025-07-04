<?php

defined('ABSPATH') || defined('DUPXABSPATH') || exit;

use VendorDuplicator\Amk\JsonSerialize\JsonSerialize;
use VendorDuplicator\Amk\JsonSerialize\JsonUnserializeMap;
use Duplicator\Addons\ProBase\License\License;
use Duplicator\Core\Controllers\ControllersManager;
use Duplicator\Core\MigrationMng;
use Duplicator\Libs\Index\FileIndexManager;
use Duplicator\Installer\Package\ArchiveDescriptor;
use Duplicator\Installer\Package\DescriptorDBTableInfo;
use Duplicator\Libs\DupArchive\Headers\DupArchiveFileHeader;
use Duplicator\Libs\DupArchive\Headers\DupArchiveHeader;
use Duplicator\Libs\DupArchive\Processors\DupArchiveProcessingFailure;
use Duplicator\Installer\Package\InstallerDescriptors;
use Duplicator\Libs\Snap\SnapIO;
use Duplicator\Libs\Snap\SnapOrigFileManager;
use Duplicator\Libs\Snap\SnapString;
use Duplicator\Libs\Snap\SnapUtil;
use Duplicator\Libs\Snap\SnapWP;
use Duplicator\Libs\WpConfig\WPConfigTransformer;
use Duplicator\Models\BrandEntity;
use Duplicator\Models\Storages\AbstractStorageEntity;
use Duplicator\Models\Storages\Local\LocalStorage;
use Duplicator\Models\Storages\StoragesUtil;
use Duplicator\Models\Storages\UnknownStorage;
use Duplicator\Models\SystemGlobalEntity;
use Duplicator\Package\Create\BuildComponents;
use Duplicator\Package\Create\BuildProgress;
use Duplicator\Package\Create\DatabaseInfo;
use Duplicator\Package\Create\DbBuildProgress;
use Duplicator\Package\Create\DupArchive\PackageDupArchiveCreateState;
use Duplicator\Package\Create\DupArchive\PackageDupArchiveExpandState;
use Duplicator\Package\NameFormat;
use Duplicator\Package\Recovery\RecoveryPackage;
use Duplicator\Package\Recovery\RecoveryStatus;
use Duplicator\Package\SettingsUtils;
use Duplicator\Utils\Crypt\CryptBlowfish;
use Duplicator\Utils\ExpireOptions;

require_once(DUPLICATOR____PATH . '/classes/package/class.pack.upload.info.php');
require_once(DUPLICATOR____PATH . '/classes/package/class.pack.multisite.php');
require_once(DUPLICATOR____PATH . '/classes/class.exceptions.php');

final class DUP_PRO_PackageStatus
{
    const REQUIREMENTS_FAILED = -6;
    const STORAGE_FAILED      = -5;
    const STORAGE_CANCELLED   = -4;
    const PENDING_CANCEL      = -3;
    const BUILD_CANCELLED     = -2;
    const ERROR               = -1;
    const PRE_PROCESS         = 0;
    const SCANNING            = 3;
    const SCAN_VALIDATION     = 4;
    const AFTER_SCAN          = 5;
    const START               = 10;
    const DBSTART             = 20;
    const DBDONE              = 39;
    const ARCSTART            = 40;
    const ARCVALIDATION       = 60;
    const ARCDONE             = 65;
    const COPIEDPACKAGE       = 70;
    const STORAGE_PROCESSING  = 75;
    const COMPLETE            = 100;
}

final class DUP_PRO_PackageType
{
    const MANUAL    = 0;
    const SCHEDULED = 1;
    const RUN_NOW   = 2;
}

abstract class DUP_PRO_Package_File_Type
{
    const Installer = 0;
    const Archive   = 1;
    const SQL       = 2;
    const Log       = 3;
    const Dump      = 4;
    const Scan      = 5;
}

/**
 * Class used to store and process all Backup logic
 *
 * @package Dupicator\classes
 */
class DUP_PRO_Package
{
    const OPT_ACTIVE = 'duplicator_pro_package_active';

    const FLAG_MANUAL                = 'MANUAL';
    const FLAG_SCHEDULE              = 'SCHEDULE';
    const FLAG_SCHEDULE_RUN_NOW      = 'SCHEDULE_RUN_NOW';
    const FLAG_DB_ONLY               = 'DB_ONLY';
    const FLAG_MEDIA_ONLY            = 'MEDIA_ONLY';
    const FLAG_HAVE_LOCAL            = 'HAVE_LOCAL';
    const FLAG_HAVE_REMOTE           = 'HAVE_REMOTE';
    const FLAG_DISASTER_AVAIABLE     = 'DISASTER_AVAIABLE';
    const FLAG_DISASTER_SET          = 'DISASTER_SET';
    const FLAG_CREATED_AFTER_RESTORE = 'CREATED_AFTER_RESTORE';
    const FLAG_ZIP_ARCHIVE           = 'ZIP_ARCHIVE';
    const FLAG_DUP_ARCHIVE           = 'DUP_ARCHIVE';
    const FLAG_ACTIVE                = 'ACTIVE'; // For future use
    const FLAG_TEMPLATE              = 'TEMPLATE'; // For future use
    const PACKAGE_HASH_DATE_FORMAT   = 'YmdHis';

    /** @var int<-1,max> */
    public $ID = -1;
    /** @var string */
    public $VersionWP = '';
    /** @var string */
    public $VersionDB = '';
    /** @var string */
    public $VersionPHP = '';
    /** @var string */
    public $VersionOS = '';
    /** @var string */
    protected $Name = '';
    /** @var string */
    protected $Hash = '';
    /** @var int Enum DUP_PRO_PackageType */
    public $Type = DUP_PRO_PackageType::MANUAL;
    /** @var string */
    public $notes = '';
    /** @var string */
    public $StorePath = DUPLICATOR_PRO_SSDIR_PATH_TMP;
    /** @var string */
    public $StoreURL = DUPLICATOR_PRO_SSDIR_URL . '/';
    /** @var string */
    public $ScanFile = '';
    /** @var float */
    public $timer_start = -1;
    /** @var string */
    public $Runtime = '';
    /** @var string */
    public $ExeSize = '0';
    /** @var string */
    public $ZipSize = '0';
    /** @var string */
    public $Brand = '';
    /** @var int<-2,max> */
    public $Brand_ID = -2;
    /** @var int Enum DUP_PRO_ZipArchive_Mode */
    public $ziparchive_mode = DUP_PRO_ZipArchive_Mode::Multithreaded;
    /** @var DUP_PRO_Archive */
    public $Archive;
    /** @var DUP_PRO_Multisite */
    public $Multisite;
    /** @var DUP_PRO_Installer */
    public $Installer;
    /** @var DUP_PRO_Database */
    public $Database;
    /** @var string[] */
    public $components = [];

    /** @var float */
    public $Status = DUP_PRO_PackageStatus::PRE_PROCESS;
    /** @var int<-1,max> */
    public $schedule_id = -1;
    // Schedule ID that created this
    // Chunking progress through build and storage uploads

    /** @var InstallerDescriptors */
    private $descriptorsMng;
    /** @var BuildProgress */
    public $build_progress;
    /** @var DbBuildProgress */
    public $db_build_progress;
    /** @var DUP_PRO_Package_Upload_Info[] */
    public $upload_infos = [];
    /** @var int<-1,max> */
    public $active_storage_id = -1;
    /** @var int<-1,max> */
    public $template_id = -1;
    /** @var bool */
    protected $buildEmailSent = false;

    /** @var string */
    protected $version = DUPLICATOR_PRO_VERSION;
    /** @var string */
    protected $created = '';
    /** @var string */
    protected $updated = '';
    /** @var string[] list ENUM self::FLAG_* */
    protected $flags = [];
    /** @var bool */
    protected $flagUpdatedAfterLoad = true;

    /**
     * Class contructor
     *
     * @param int                             $type       DUP_PRO_PackageType ENUM
     * @param int[]                           $storageIds Storages id
     * @param DUP_PRO_Package_Template_Entity $template   Template for Backup or null
     * @param DUP_PRO_Schedule_Entity         $schedule   Schedule for Backup or null
     */
    public function __construct(
        $type = DUP_PRO_PackageType::MANUAL,
        $storageIds = [],
        ?DUP_PRO_Package_Template_Entity $template = null,
        ?DUP_PRO_Schedule_Entity $schedule = null
    ) {
        global $wp_version;

        switch ($type) {
            case DUP_PRO_PackageType::MANUAL:
                $this->Type = DUP_PRO_PackageType::MANUAL;
                break;
            case DUP_PRO_PackageType::SCHEDULED:
                $this->Type = DUP_PRO_PackageType::SCHEDULED;
                break;
            case DUP_PRO_PackageType::RUN_NOW:
                $this->Type = DUP_PRO_PackageType::RUN_NOW;
                break;
            default:
                throw new Exception("Package type $type not supported");
        }

        $this->VersionOS  = defined('PHP_OS') ? PHP_OS : 'unknown';
        $this->VersionWP  = $wp_version;
        $this->VersionPHP = phpversion();
        $dbversion        = DUP_PRO_DB::getVersion();
        $this->VersionDB  = (empty($dbversion) ? '- unknown -' : $dbversion);

        if ($schedule !== null) {
            $this->schedule_id = $schedule->getId();
        }

        $timestamp     = time();
        $this->created = gmdate("Y-m-d H:i:s", $timestamp);
        $this->Name    = $this->getNameFromFormat($template, $timestamp);
        $this->Hash    = $this->make_hash();

        $this->components = BuildComponents::COMPONENTS_DEFAULT;

        $this->Database          = new DUP_PRO_Database($this);
        $this->Archive           = new DUP_PRO_Archive($this);
        $this->Multisite         = new DUP_PRO_Multisite();
        $this->Installer         = new DUP_PRO_Installer($this);
        $this->build_progress    = new BuildProgress();
        $this->db_build_progress = new DbBuildProgress();

        $this->build_progress->setBuildMode();

        $this->setByTemplate($template);
        if (empty($storageIds)) {
            $storageIds = [StoragesUtil::getDefaultStorageId()];
        }
        $this->add_upload_infos($storageIds);
        $this->updatePackageFlags();
    }

    /**
     * Set properties by template
     *
     * @param DUP_PRO_Package_Template_Entity $template template
     *
     * @return void
     */
    protected function setByTemplate(?DUP_PRO_Package_Template_Entity $template = null)
    {
        if ($template === null) {
            return;
        }

        //BRAND
        $brand_data = BrandEntity::getByIdOrDefault((int) $template->installer_opts_brand);
        $brand_data->prepareAttachmentsInstaller();
        $this->Brand    = $brand_data->name;
        $this->Brand_ID = $brand_data->getId();

        //MULTISITE
        $this->Multisite->FilterSites = $template->filter_sites;

        //ARCHIVE
        $this->components           = $template->components;
        $this->Archive->FilterOn    = $template->archive_filter_on;
        $this->Archive->FilterDirs  = $template->archive_filter_dirs;
        $this->Archive->FilterExts  = $template->archive_filter_exts;
        $this->Archive->FilterFiles = $template->archive_filter_files;
        $this->Archive->FilterNames = $template->archive_filter_names;

        //INSTALLER
        $this->Installer->OptsDBHost   = $template->installer_opts_db_host;
        $this->Installer->OptsDBName   = $template->installer_opts_db_name;
        $this->Installer->OptsDBUser   = $template->installer_opts_db_user;
        $this->Installer->OptsSecureOn = $template->installer_opts_secure_on;
        $this->Installer->passowrd     = $template->installerPassowrd;
        $this->Installer->OptsSkipScan = $template->installer_opts_skip_scan;

        // CPANEL
        $this->Installer->OptsCPNLEnable   = $template->installer_opts_cpnl_enable;
        $this->Installer->OptsCPNLHost     = $template->installer_opts_cpnl_host;
        $this->Installer->OptsCPNLUser     = $template->installer_opts_cpnl_user;
        $this->Installer->OptsCPNLDBAction = $template->installer_opts_cpnl_db_action;
        $this->Installer->OptsCPNLDBHost   = $template->installer_opts_cpnl_db_host;
        $this->Installer->OptsCPNLDBName   = $template->installer_opts_cpnl_db_name;
        $this->Installer->OptsCPNLDBUser   = $template->installer_opts_cpnl_db_user;

        //DATABASE
        $this->Database->FilterOn        = $template->database_filter_on;
        $this->Database->prefixFilter    = $template->databasePrefixFilter;
        $this->Database->prefixSubFilter = $template->databasePrefixSubFilter;
        $this->Database->FilterTables    = $template->database_filter_tables;
        $this->Database->Compatible      = $template->database_compatibility_modes;
    }

    /**
     * Destructor
     *
     * @return void
     */
    public function __destruct()
    {
        unset($this->Database);
        unset($this->Archive);
        unset($this->Multisite);
        unset($this->Installer);
        unset($this->build_progress);
        unset($this->db_build_progress);
        foreach ($this->upload_infos as $obj) {
            unset($obj);
        }
        unset($this->upload_infos);
    }

    /**
     * Clone
     *
     * @return void
     */
    public function __clone()
    {
        $this->Database          = clone $this->Database;
        $this->Archive           = clone $this->Archive;
        $this->Multisite         = clone $this->Multisite;
        $this->Installer         = clone $this->Installer;
        $this->build_progress    = clone $this->build_progress;
        $this->db_build_progress = clone $this->db_build_progress;
        $cloneInfo               = [];
        foreach ($this->upload_infos as $key => $obj) {
            $cloneInfo[$key] = clone $obj;
        }
        $this->upload_infos = $cloneInfo;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->Name;
    }

    /**
     * Get hash
     *
     * @return string
     */
    public function getHash()
    {
        return $this->Hash;
    }

    /**
     * Get name hash
     *
     * @return string
     */
    public function getNameHash()
    {
        return $this->Name . '_' . $this->Hash;
    }

    /**
     * Returns true if this is a DB only Backup
     *
     * @return bool
     */
    public function isDBOnly()
    {
        return BuildComponents::isDBOnly($this->components) || $this->Archive->ExportOnlyDB;
    }

    /**
     * Returns true if this is a File only Backup
     *
     * @return bool
     */
    public function isDBExcluded()
    {
        return BuildComponents::isDBExcluded($this->components);
    }

    /**
     * Cancel all uploads
     *
     * @return void
     */
    public function cancel_all_uploads()
    {
        DUP_PRO_Log::trace("Cancelling all uploads");
        foreach ($this->upload_infos as $upload_info) {
            if ($upload_info->has_completed() == false) {
                $upload_info->cancelTransfer();
            }
        }
    }

    /**
     * Get upload infos
     *
     * @return array<int,DUP_PRO_Package_Upload_Info>
     */
    public function get_latest_upload_infos(): array
    {
        $upload_infos = [];
        // Just save off the latest per the storage id
        foreach ($this->upload_infos as $upload_info) {
            $upload_infos[$upload_info->getStorageId()] = $upload_info;
        }

        return $upload_infos;
    }

    /**
     * What % along we are in the given status level
     *
     * @return float
     */
    public function get_status_progress()
    {
        if ($this->Status != DUP_PRO_PackageStatus::STORAGE_PROCESSING) {
            return 0.0;
        }

        if ($this->isDownloadInProgress()) {
            $activeInfo = null;
            foreach ($this->upload_infos as $uInfo) {
                if ($uInfo->isDownloadFromRemote() && $uInfo->has_completed() === false) {
                    $activeInfo = $uInfo;
                }
            }

            if (is_null($activeInfo)) {
                return 0.0;
            }

            return round($activeInfo->progress, 1);
        }

        $completed_infos  = 0;
        $total_infos      = count($this->upload_infos);
        $partial_progress = 0;
        foreach ($this->upload_infos as $upload_info) {
            if ($upload_info->isDownloadFromRemote()) {
                continue;
            }

            if ($upload_info->has_completed()) {
                $completed_infos++;
            } else {
                $partial_progress += $upload_info->progress;
            }
        }

        DUP_PRO_Log::trace("partial progress $partial_progress");
        DUP_PRO_Log::trace("completed infos before $completed_infos");
        $bcd = ($partial_progress / (float) 100);
        DUP_PRO_Log::trace("partial progress info contributor=$bcd");
        $completed_infos += $bcd;
        DUP_PRO_Log::trace("completed infos after $completed_infos");
        // Add on the particulars where the latest guy is at
        // return 100 * (bcdiv($completed_infos, $total_infos, 2));
        return DUP_PRO_U::percentage($completed_infos, $total_infos, 0);
    }

    /**
     * Add upload info
     *
     * @param int[] $storage_ids storage ids
     *
     * @return void
     */
    public function add_upload_infos($storage_ids)
    {
        DUP_PRO_Log::trace('adding upload infos');
        $this->upload_infos = [];
        foreach ($storage_ids as $storage_id) {
            $storage_id_is_exist = AbstractStorageEntity::exists($storage_id);
            if ($storage_id_is_exist) {
                $this->upload_infos[] = new DUP_PRO_Package_Upload_Info($storage_id);
            }
        }
        DUP_PRO_Log::trace("Number update infos added:" . count($this->upload_infos));
    }

    /**
     * Get display size
     *
     * @return string
     */
    public function get_display_size()
    {
        $global = DUP_PRO_Global_Entity::getInstance();
        if ($this->Status == 100 || $this->transferWasInterrupted()) {
            return DUP_PRO_U::byteSize($this->Archive->Size);
        } elseif (
            ($this->build_progress->current_build_mode == DUP_PRO_Archive_Build_Mode::DupArchive) &&
            ($this->Status >= DUP_PRO_PackageStatus::ARCVALIDATION) &&
            ($this->Status <= DUP_PRO_PackageStatus::ARCDONE)
        ) {
            return __('Validating', 'duplicator-pro');
        } elseif (
            (($this->build_progress->current_build_mode == DUP_PRO_Archive_Build_Mode::Shell_Exec) ||
                (($this->build_progress->current_build_mode == DUP_PRO_Archive_Build_Mode::ZipArchive) &&
                    ($global->ziparchive_mode == DUP_PRO_ZipArchive_Mode::SingleThread))) &&
            ($this->Status <= DUP_PRO_PackageStatus::ARCDONE) &&
            ($this->Status >= DUP_PRO_PackageStatus::PRE_PROCESS)
        ) {
            return __('Building', 'duplicator-pro');
        } else {
            $size              = 0;
            $temp_archive_path = DUPLICATOR_PRO_SSDIR_PATH_TMP . '/' . $this->get_archive_filename();
            $archive_path      = DUPLICATOR_PRO_SSDIR_PATH . '/' . $this->get_archive_filename();
            if (file_exists($archive_path)) {
                $size = @filesize($archive_path);
            } elseif (file_exists($temp_archive_path)) {
                $size = @filesize($temp_archive_path);
            } else {
                //  DUP_PRO_Log::trace("Couldn't find archive for file size");
            }
            return DUP_PRO_U::byteSize($size);
        }
    }

    /**
     * Get scan filename
     *
     * @return string
     */
    public function get_scan_filename()
    {
        return $this->getNameHash() . '_scan.json';
    }

    /**
     * Get scan URL
     *
     * @return string
     */
    public function get_scan_url()
    {
        return $this->StoreURL . $this->get_scan_filename();
    }

    /**
     * Get safe scan filepath
     *
     * @return string
     */
    public function get_safe_scan_filepath()
    {
        $filename = $this->get_scan_filename();
        return SnapIO::safePath(DUPLICATOR_PRO_SSDIR_PATH . "/$filename");
    }

    /**
     * Get log filename
     *
     * @return string
     */
    public function get_log_filename()
    {
        return $this->getNameHash() . '_log.txt';
    }

    /**
     * @return string Url to the Backup's log file
     */
    public function get_log_url()
    {
        $link_log = $this->StoreURL . $this->get_log_filename();
        if (!file_exists($this->get_safe_log_filepath())) {
            // backward compatibility
            $link_log = "{$this->StoreURL}{$this->getNameHash()}.log";
        }

        return $link_log;
    }

    /**
     * Get dump filename
     *
     * @return string
     */
    public function get_dump_filename()
    {
        return $this->getNameHash() . '_dump.txt';
    }

    /**
     * Get safe log filepath
     *
     * @return string
     */
    public function get_safe_log_filepath()
    {
        $filename = $this->get_log_filename();
        return SnapIO::safePath(DUPLICATOR_PRO_SSDIR_PATH . "/$filename");
    }

    /**
     * Dump file exists
     *
     * @return bool
     */
    public function dump_file_exists()
    {
        $filename = $this->get_dump_filename();
        $filepath = SnapIO::safePath(DUPLICATOR_PRO_DUMP_PATH . "/$filename");
        return file_exists($filepath);
    }

    /**
     * Get upload info for storage id
     *
     * @param int $storage_id storage id
     *
     * @return ?DUP_PRO_Package_Upload_Info upload info or null if not found
     */
    public function get_upload_info_for_storage_id($storage_id)
    {
        $selected_upload_info = null;
        foreach ($this->upload_infos as $upload_info) {
            if ($upload_info->getStorageId() == $storage_id) {
                $selected_upload_info = &$upload_info;
                break;
            }
        }

        return $selected_upload_info;
    }

    /**
     * Marks the backup as not existing in the storage. If the removeBackup flag is set to true
     * and the backup does not exist in any storage, the backup record will be removed from the database.
     *
     * @param int  $storageId    Storage ID
     * @param bool $removeBackup If true, the backup record will be removed from the database
     *                           if it does not exist in any storage
     *
     * @return bool True if the backup record was removed from the database
     */
    public function unsetStorage($storageId, $removeBackup = false): bool
    {
        if (($uploadInfo = $this->get_upload_info_for_storage_id($storageId)) !== null) {
            $uploadInfo->setPackageExists(false);
            if (!$this->update()) {
                DUP_PRO_Log::trace("Failed to update backup record with ID: " . $this->ID);
                return false;
            }
        }

        if (!$removeBackup || $this->hasValidStorage()) {
            return false;
        }

        if (!$this->delete()) {
            DUP_PRO_Log::trace("Failed to remove Backup record with ID: " . $this->ID);
            return false;
        }

        return true;
    }

    /**
     * Get local Backup file
     *
     * @param int $file_type DUP_PRO_Package_File_Type
     *
     * @return bool|string file path or false if don't exists
     */
    public function getLocalPackageFilePath($file_type)
    {
        switch ($file_type) {
            case DUP_PRO_Package_File_Type::Installer:
                $fileName = $this->Installer->getInstallerLocalName();
                break;
            case DUP_PRO_Package_File_Type::Archive:
                $fileName = $this->get_archive_filename();
                break;
            case DUP_PRO_Package_File_Type::Log:
                $fileName = $this->get_log_filename();
                break;
            default:
                throw new Exception("File type $file_type not supported");
        }

        //First check if default file exists
        if (file_exists($filePath = SnapIO::trailingslashit(DUPLICATOR_PRO_SSDIR_PATH) . $fileName)) {
            return SnapIO::safePath($filePath);
        }

        foreach ($this->getLocalStorages() as $localStorage) {
            $filePath = SnapIO::trailingslashit($localStorage->getLocationString()) . $fileName;
            if (file_exists($filePath)) {
                return SnapIO::safePath($filePath);
            }
        }

        return false;
    }

    /**
     * @param int $fileType Type of File to be Downloaded (DUP_PRO_Package_File_Type)
     *
     * @return string URL at which the file can be downloaded
     */
    public function getLocalPackageFileURL($fileType)
    {
        if ($fileType == DUP_PRO_Package_File_Type::Log) {
            return $this->get_log_url();
        }

        if (!$this->getLocalPackageFilePath($fileType)) {
            return "";
        }

        switch ($fileType) {
            case DUP_PRO_Package_File_Type::Installer:
                return $this->getLocalPackageAjaxDownloadURL(DUP_PRO_Package_File_Type::Installer);
            case DUP_PRO_Package_File_Type::Archive:
                return file_exists(SnapIO::trailingslashit(DUPLICATOR_PRO_SSDIR_PATH) . $this->get_archive_filename())
                    ? $this->Archive->getURL()
                    : $this->getLocalPackageAjaxDownloadURL(DUP_PRO_Package_File_Type::Archive);
            default:
                throw new Exception("File type $fileType not supported");
        }
    }

    /**
     * Get download security token
     *
     * @param string $hash hash
     *
     * @return string
     */
    public static function getLocalPackageAjaxDownloadToken($hash)
    {
        return md5($hash . CryptBlowfish::getDefaultKey());
    }

    /**
     * Get local Backup ajax download url
     *
     * @param int $fileType Type of File to be Downloaded (DUP_PRO_Package_File_Type)
     *
     * @return string URL at which the file can be downloaded
     */
    public function getLocalPackageAjaxDownloadURL($fileType)
    {
        return admin_url('admin-ajax.php') . "?" . http_build_query([
            'action'   => 'duplicator_pro_download_package_file',
            'hash'     =>  $this->Hash,
            'token'    =>  self::getLocalPackageAjaxDownloadToken($this->Hash),
            'fileType' => $fileType,
        ]);
    }

    /**
     * Return list of local storages
     *
     * @return AbstractStorageEntity[]
     */
    public function getLocalStorages(): array
    {
        $storages = [];
        foreach ($this->upload_infos as $upload_info) {
            if (!$upload_info->isLocal()) {
                continue;
            }
            $storages[] = $upload_info->getStorage();
        }
        return $storages;
    }

    /**
     * Validates the inputs from the UI for correct data input
     *
     * @return DUP_PRO_Validator
     */
    public function validateInputs()
    {
        $validator = new DUP_PRO_Validator();

        if ($this->Archive->FilterOn) {
            $validator->explode_filter_custom(
                $this->Archive->FilterDirs,
                ';',
                DUP_PRO_Validator::FILTER_VALIDATE_FOLDER_WITH_COMMENT,
                [
                    'valkey' => 'FilterDirs',
                    'errmsg' => __('Directory: <b>%1$s</b> is an invalid path. Please remove the value from the Archive > Files Tab > Folders input box and apply only valid paths.', 'duplicator-pro'),
                ]
            );

            $validator->explode_filter_custom(
                $this->Archive->FilterExts,
                ';',
                DUP_PRO_Validator::FILTER_VALIDATE_FILE_EXT,
                [
                    'valkey' => 'FilterExts',
                    'errmsg' => __('File extension: <b>%1$s</b> is an invalid extension name. Please remove the value from the Archive > Files Tab > File Extensions input box and apply only valid extensions. For example \'jpg\'', 'duplicator-pro'),
                ]
            );

            $validator->explode_filter_custom(
                $this->Archive->FilterFiles,
                ';',
                DUP_PRO_Validator::FILTER_VALIDATE_FILE_WITH_COMMENT,
                [
                    'valkey' => 'FilterFiles',
                    'errmsg' => __('File: <b>%1$s</b> is an invalid file name. Please remove the value from the Archive > Files Tab > Files input box and apply only valid file names.', 'duplicator-pro'),
                ]
            );
        }

        //FILTER_VALIDATE_DOMAIN throws notice message on PHP 5.6
        if (defined('FILTER_VALIDATE_DOMAIN')) {
            // phpcs:ignore PHPCompatibility.Constants.NewConstants.filter_validate_domainFound
            $validator->filter_var($this->Installer->OptsDBHost, FILTER_VALIDATE_DOMAIN, [
                'valkey'   => 'OptsDBHost',
                'errmsg'   => __('MySQL Server Host: <b>%1$s</b> isn\'t a valid host', 'duplicator-pro'),
                'acc_vals' => [
                    '',
                    'localhost',
                ],
            ]);
        }

        return $validator;
    }

    /**
     * Process storage upload
     *
     * @return bool
     */
    public function process_storages()
    {
        //START LOGGING
        DUP_PRO_Log::open($this->getNameHash());
        DUP_PRO_Log::info("-----------------------------------------");
        DUP_PRO_Log::info("STORAGE PROCESSING THREAD INITIATED");
        $complete = (count($this->upload_infos) == 0);
        // Indicates if all storages have finished (succeeded or failed all-together)

        $error_present         = false;
        $local_default_present = false;
        if (!$complete) {
            $complete            = true;
            $latest_upload_infos = $this->get_latest_upload_infos();

            foreach ($latest_upload_infos as $upload_info) {
                if ($upload_info->isDefaultStorage()) {
                    $local_default_present = true;
                }

                if ($upload_info->isFailed()) {
                    DUP_PRO_Log::trace("The following Upload Info is marked as failed");
                    DUP_PRO_Log::traceObject('upload_info var:', $upload_info);
                    $error_present = true;
                } elseif ($upload_info->has_completed() == false) {
                    DUP_PRO_Log::trace("The following Upload Info hasn't completed yet");
                    DUP_PRO_Log::traceObject('upload_info var:', $upload_info);
                    $complete = false;
                    DUP_PRO_Log::trace("Telling storage id {$upload_info->getStorageId()} to process");
                    if (($storage = AbstractStorageEntity::getById($upload_info->getStorageId())) === false) {
                        DUP_PRO_Log::trace('Storage Object is null. Maybe storage is deleted.');
                    } else {
                        if ($upload_info->has_started() === false) {
                            DUP_PRO_Log::trace("Upload Info hasn't started yet, starting it");
                            $upload_info->start();
                        }

                        // Process a bit of work then let the next cron take care of if it's completed or not.
                        StoragesUtil::processPackage($this, $upload_info);
                    }
                    break;
                } else {
                    if (($storage = AbstractStorageEntity::getById($upload_info->getStorageId())) !== false) {
                        $storage_type_string = strtoupper($storage->getStypeName());
                        DUP_PRO_Log::trace(
                            "Upload Info already completed for storage id: " . $upload_info->getStorageId() .
                                ", type: " . $storage_type_string . ", name: " . $storage->getName()
                        );
                    }
                }
            }
        } else {
            DUP_PRO_Log::trace("No storage ids defined for Backup $this->ID!");
            $error_present = true;
        }

        if ($complete) {
            DUP_PRO_Log::info("STORAGE PROCESSING COMPLETED");
            if ($error_present) {
                DUP_PRO_Log::trace("Storage error is present");
                $this->set_status(DUP_PRO_PackageStatus::COMPLETE);
                $this->post_scheduled_build_processing(1, false);
                if ($local_default_present == false) {
                    DUP_PRO_Log::trace("Deleting Backup files from default location.");
                    self::delete_default_local_files($this->getNameHash(), true, false);
                }
            } else {
                if ($local_default_present == false) {
                    DUP_PRO_Log::trace("Deleting Backup files from default location.");
                    self::delete_default_local_files($this->getNameHash(), true, false);
                } else {
                    $default_local_storage = StoragesUtil::getDefaultStorage();
                    DUP_PRO_Log::trace('Purge old default local storage Backups');
                    $default_local_storage->purgeOldPackages();
                }
                $this->set_status(DUP_PRO_PackageStatus::COMPLETE);
                $this->post_scheduled_build_processing(1, true);
            }
            do_action('duplicator_pro_package_transfer_completed', $this);
        }

        return $complete;
    }

    /**
     * Conditions Example
     * [
     *   relation = 'AND',
     *   [ 'op' => '>=' , 'status' =>  DUP_PRO_PackageStatus::START ]
     *   [ 'op' => '<' , 'status' =>  DUP_PRO_PackageStatus::COMPLETED ]
     * ]
     *
     * @param array<string|int,string|array{op:string,status:int}> $conditions Conditions
     *
     * @return string
     */
    protected static function statusContitionsToWhere($conditions = [])
    {
        $accepted_op = [
            '<',
            '>',
            '=',
            '<>',
            '>=',
            '<=',
        ];
        $relation    = (isset($conditions['relation']) && strtoupper($conditions['relation']) == 'OR') ? ' OR ' : ' AND ';
        unset($conditions['relation']);
        $where = '';
        if (!empty($conditions)) {
            $str_conds = [];
            foreach ($conditions as $cond) {
                $op          = (isset($cond['op']) && in_array($cond['op'], $accepted_op)) ? $cond['op'] : '=';
                $status      = isset($cond['status']) ? (int) $cond['status'] : 0;
                $str_conds[] = 'status ' . $op . ' ' . $status;
            }

            $where = implode($relation, $str_conds) . ' ';
        } else {
            $where = '1 ';
        }

        return $where;
    }

    /**
     * Select Backups from database
     *
     * @param string $where      where conditions
     * @param int    $limit      max row numbers if 0 the limit is PHP_INT_MAX
     * @param int    $offset     offset 0 is at begin
     * @param string $orderBy    default `id` ASC if empty no order
     * @param string $resultType ids => int[], row => row without Backup blob, fullRow => row with Backup blob, objs => DUP_Package objects[]
     *
     * @return DUP_PRO_Package[]|object[]|int[]
     */
    public static function dbSelect(
        $where,
        $limit = 0,
        $offset = 0,
        $orderBy = '`id` ASC',
        $resultType = 'objs'
    ) {
        global $wpdb;
        $table      = self::getTableName();
        $where      = ' WHERE ' . (strlen($where) > 0 ? $where : '1');
        $packages   = [];
        $offsetStr  = $wpdb->prepare(' OFFSET %d', $offset);
        $limitStr   = $wpdb->prepare(' LIMIT %d', ($limit > 0 ? $limit : PHP_INT_MAX));
        $orderByStr = empty($orderBy) ? '' : ' ORDER BY ' . $orderBy . ' ';
        switch ($resultType) {
            case 'ids':
                $cols = '`id`';
                break;
            case 'row':
                $cols = '`id`,`name`,`hash`,`archive_name`,`status`,`progress`,`flags`,`owner`,`version`,`created`,`updated_at`';
                break;
            case 'fullRow':
            case 'objs':
            default:
                $cols = '*';
                break;
        }

        $rows = $wpdb->get_results('SELECT ' . $cols . ' FROM `' . $table . '` ' . $where . $orderByStr . $limitStr . $offsetStr);
        if ($rows != null) {
            switch ($resultType) {
                case 'ids':
                    foreach ($rows as $row) {
                        $packages[] = $row->id;
                    }
                    break;
                case 'row':
                case 'fullRow':
                    $packages = $rows;
                    break;
                case 'objs':
                default:
                    foreach ($rows as $row) {
                        $package = self::package_from_row($row);
                        if ($package != null) {
                            $packages[] = $package;
                        }
                    }
            }
        }
        return $packages;
    }

    /**
     * Execute $callback function foreach Backup result
     *
     * @param callable $callback function callback(DUP_PRO_Package $package)
     * @param string   $where    where conditions
     * @param int      $limit    max row numbers if 0 the limit is PHP_INT_MAX
     * @param int      $offset   offset 0 is at begin
     * @param string   $orderBy  default `id` ASC if empty no order
     *
     * @return void
     */
    public static function dbSelectCallback(
        $callback,
        $where,
        $limit = 0,
        $offset = 0,
        $orderBy = '`id` ASC'
    ) {
        if (!is_callable($callback)) {
            throw new Exception('No callback function passed');
        }

        $ids = self::dbSelect($where, $limit, $offset, $orderBy, 'ids');

        foreach ($ids as $id) {
            if (($package = self::get_by_id($id)) == false) {
                continue;
            }

            call_user_func($callback, $package);
            unset($package);
        }
    }

    /**
     * Get Backups with status conditions and/or pagination
     * Conditions Example
     * [
     *   relation = 'AND',
     *   [ 'op' => '>=' , 'status' =>  DUP_PRO_PackageStatus::START ]
     *   [ 'op' => '<' , 'status' =>  DUP_PRO_PackageStatus::COMPLETED ]
     * ]
     *
     * @param array<string|int,string|array{op:string,status:int}> $conditions Conditions if empty get all Backups
     * @param int                                                  $limit      max row numbers if 0 the limit is PHP_INT_MAX
     * @param int                                                  $offset     offset 0 is at begin
     * @param string                                               $orderBy    default `id` ASC if empty no order
     * @param string                                               $resultType ids => int[], row => row without Backup blob, fullRow => row with Backup blob, objs => DUP_Package objects[]
     *
     * @return DUP_PRO_Package[]|object[]|int[]
     */
    public static function get_packages_by_status(
        $conditions = [],
        $limit = 0,
        $offset = 0,
        $orderBy = '`id` ASC',
        $resultType = 'objs'
    ) {
        return self::dbSelect(self::statusContitionsToWhere($conditions), $limit, $offset, $orderBy, $resultType);
    }

    /**
     * Get Backups row db with status conditions and/or pagination
     *
     * Conditions Example
     * [
     *   relation = 'AND',
     *   [ 'op' => '>=' , 'status' =>  DUP_PRO_PackageStatus::START ]
     *   [ 'op' => '<' , 'status' =>  DUP_PRO_PackageStatus::COMPLETED ]
     * ]
     *
     * @param array<string|int,string|array{op:string,status:int}> $conditions Conditions if empty get all Backups
     * @param int                                                  $limit      max row numbers if 0 the limit is PHP_INT_MAX
     * @param int                                                  $offset     offset 0 is at begin
     * @param string                                               $orderBy    default `id` ASC if empty no order
     *
     * @return object[]      // return row database without Backup blob
     */
    public static function get_row_by_status(
        $conditions = [],
        $limit = 0,
        $offset = 0,
        $orderBy = '`id` ASC'
    ) {
        return self::dbSelect(self::statusContitionsToWhere($conditions), $limit, $offset, $orderBy, 'row');
    }

    /**
     * Get Backups ids with status conditions and/or pagination
     * Conditions Example
     * [
     *   relation = 'AND',
     *   [ 'op' => '>=' , 'status' =>  DUP_PRO_PackageStatus::START ]
     *   [ 'op' => '<' , 'status' =>  DUP_PRO_PackageStatus::COMPLETED ]
     * ]
     *
     * @param array<string|int,string|array{op:string,status:int}> $conditions Conditions if empty get all Backups
     * @param int                                                  $limit      max row numbers if 0 the limit is PHP_INT_MAX
     * @param int                                                  $offset     offset 0 is at begin
     * @param string                                               $orderBy    default `id` ASC if empty no order
     *
     * @return int[] return row database without Backup blob
     */
    public static function get_ids_by_status(
        $conditions = [],
        $limit = 0,
        $offset = 0,
        $orderBy = '`id` ASC'
    ) {
        return self::dbSelect(self::statusContitionsToWhere($conditions), $limit, $offset, $orderBy, 'ids');
    }

    /**
     * count Backup with status condition
     * Conditions Example
     * [
     *   relation = 'AND',
     *   [ 'op' => '>=' , 'status' =>  DUP_PRO_PackageStatus::START ]
     *   [ 'op' => '<' , 'status' =>  DUP_PRO_PackageStatus::COMPLETED ]
     * ]
     *
     * @param array<string|int,string|array{op:string,status:int}> $conditions Conditions if empty get all Backups
     *
     * @return int
     */
    public static function count_by_status($conditions = [])
    {
        global $wpdb;
        $table = self::getTableName();
        $where = self::statusContitionsToWhere($conditions);
        return $wpdb->get_var("SELECT count(id) FROM `{$table}` WHERE " . $where);
    }

    /**
     * Execute $callback function foreach Backup result
     * For each iteration the memory is released
     * Conditions Example
     * [
     *   relation = 'AND',
     *   [ 'op' => '>=' , 'status' =>  DUP_PRO_PackageStatus::START ]
     *   [ 'op' => '<' , 'status' =>  DUP_PRO_PackageStatus::COMPLETED ]
     * ]
     *
     * @param callable                                             $callback   function callback(DUP_PRO_Package $package)
     * @param array<string|int,string|array{op:string,status:int}> $conditions Conditions if empty get all Backups
     * @param int                                                  $limit      max row numbers if 0 the limit is PHP_INT_MAX
     * @param int                                                  $offset     offset 0 is at begin
     * @param string                                               $orderBy    default `id` ASC if empty no order
     *
     * @return void
     */
    public static function by_status_callback(
        $callback,
        $conditions = [],
        $limit = 0,
        $offset = 0,
        $orderBy = '`id` ASC'
    ) {
        self::dbSelectCallback($callback, self::statusContitionsToWhere($conditions), $limit, $offset, $orderBy);
    }

    /**
     * Set Backup for cancellation
     *
     * @return void
     */
    public function set_for_cancel()
    {
        $pending_cancellations = self::get_pending_cancellations();
        if (!in_array($this->ID, $pending_cancellations)) {
            array_push($pending_cancellations, $this->ID);
            ExpireOptions::set(DUPLICATOR_PRO_PENDING_CANCELLATION_TRANSIENT, $pending_cancellations, DUPLICATOR_PRO_PENDING_CANCELLATION_TIMEOUT);
        }
    }

    /**
     * Get all Backups marked for cancellation
     *
     * @return int[] array of Backup ids
     */
    public static function get_pending_cancellations()
    {
        $pending_cancellations = ExpireOptions::get(DUPLICATOR_PRO_PENDING_CANCELLATION_TRANSIENT);
        if ($pending_cancellations === false) {
            $pending_cancellations = [];
        }
        return $pending_cancellations;
    }

    /**
     * Check if the Backup is marked for cancellation
     *
     * @return bool
     */
    public function is_cancel_pending()
    {
        $pending_cancellations = self::get_pending_cancellations();
        return in_array($this->ID, $pending_cancellations);
    }

    /**
     * Clear all pending cancellations
     *
     * @return void
     */
    public static function clear_pending_cancellations()
    {
        if (ExpireOptions::delete(DUPLICATOR_PRO_PENDING_CANCELLATION_TRANSIENT) == false) {
            DUP_PRO_Log::traceError("Couldn't remove pending cancel transient");
        }
    }

    /**
     *
     * @param int $id Backup ID
     *
     * @return false|DUP_PRO_Package false if fail
     */
    public static function get_by_id($id)
    {
        if ($id < 0) {
            return false;
        }

        global $wpdb;
        $table = self::getTableName();
        $sql   = $wpdb->prepare("SELECT * FROM `{$table}` where ID = %d", $id);
        $row   = $wpdb->get_row($sql);
        //DUP_PRO_Log::traceObject('Object row', $row);
        if ($row) {
            return self::package_from_row($row);
        } else {
            return false;
        }
    }

    /**
     *
     * @param string $hash Hash
     *
     * @return DUP_PRO_Package|bool false if fail
     */
    public static function get_by_hash($hash)
    {
        global $wpdb;
        $table = self::getTableName();
        $sql   = $wpdb->prepare("SELECT * FROM `{$table}` where hash = %s", $hash);
        $row   = $wpdb->get_row($sql);
        if ($row) {
            return self::package_from_row($row);
        } else {
            return false;
        }
    }

    /**
     *
     * @param object $row Database row
     *
     * @return null|DUP_PRO_Package
     */
    private static function package_from_row($row)
    {
        $package = null;

        if (strlen($row->hash) == 0) {
            DUP_PRO_Log::trace("Hash is 0 for the Backup $row->id...");
            return null;
        }

        if (property_exists($row, 'id')) {
            $row->id = (int) $row->id;
        }
        if (property_exists($row, 'status')) {
            $row->status = (int) $row->status;
        }
        if (property_exists($row, 'progress')) {
            $row->progress = (float) $row->progress;
            if (property_exists($row, 'status') && $row->progress == 0 && $row->status > 0) {
                $row->progress = (float) $row->status;
            }
        }
        if (property_exists($row, 'flags')) {
            if (strlen($row->flags) == 0) {
                $row->flags = [];
            } else {
                $row->flags = explode(',', $row->flags);
            }
        }

        try {
            $package = self::get_from_json($row->package, $row);
        } catch (Exception $ex) {
            DUP_PRO_Log::traceError("Problem getting Backup from json. msg: " . $ex->getMessage());
            return null;
        }

        return $package;
    }

    /**
     *
     * @param boolean $delete_temp Deprecated, always true
     *
     * @return boolean
     */
    public function delete($delete_temp = false)
    {
        $ret_val = false;
        global $wpdb;
        $tblName   = self::getTableName();
        $getResult = $wpdb->get_results($wpdb->prepare("SELECT name, hash FROM `{$tblName}` WHERE id = %d", $this->ID), ARRAY_A);
        if ($getResult) {
            $row       = $getResult[0];
            $name_hash = "{$row['name']}_{$row['hash']}";
            $delResult = $wpdb->query($wpdb->prepare("DELETE FROM `{$tblName}` WHERE id = %d", $this->ID));
            if ($delResult != 0) {
                $ret_val = true;
                self::delete_default_local_files($name_hash, $delete_temp);
                $this->delete_local_storage_files();
            }
        }

        return $ret_val;
    }

    /**
     * Use only in extreme cases to get rid of a runaway Backup
     *
     * @param int $id Backup ID
     *
     * @return boolean
     */
    public static function force_delete($id)
    {
        $ret_val = false;
        global $wpdb;
        $tblName   = self::getTableName();
        $getResult = $wpdb->get_results($wpdb->prepare("SELECT name, hash FROM `{$tblName}` WHERE id = %d", $id), ARRAY_A);
        if ($getResult) {
            $row       = $getResult[0];
            $name_hash = "{$row['name']}_{$row['hash']}";
            $delResult = $wpdb->query($wpdb->prepare("DELETE FROM `{$tblName}` WHERE id = %d", $id));
            if ($delResult != 0) {
                $ret_val = true;
                self::delete_default_local_files($name_hash, true);
            }
        }

        return $ret_val;
    }

    /**
     * Delete local storage files
     *
     * @return void
     */
    private function delete_local_storage_files()
    {
        $storages           = $this->get_storages();
        $archive_filename   = $this->get_archive_filename();
        $installer_filename = $this->Installer->getInstallerLocalName();
        $log_filename       = $this->get_log_filename();
        $scan_filename      = $this->get_scan_filename();
        $index_filename     = $this->getIndexFileName();

        foreach ($storages as $storage) {
            if ($storage->getSType() !== LocalStorage::getSType()) {
                continue;
            }
            $path               = $storage->getLocationString();
            $archive_filepath   = $path . "/" . $archive_filename;
            $installer_filepath = $path . "/" . $installer_filename;
            $log_filepath       = $path . "/" . $log_filename;
            $scan_filepath      = $path . "/" . $scan_filename;
            $index_filepath     = $path . "/" . $index_filename;
            @unlink($archive_filepath);
            @unlink($installer_filepath);
            @unlink($log_filepath);
            @unlink($scan_filepath);
            @unlink($index_filepath);
        }
    }

    /**
     * Delete default local files
     *
     * @param string $name_hash        Package namehash
     * @param bool   $delete_temp      if set to true will delete temp files too
     * @param bool   $delete_log_files if set to true will delete log files too
     *
     * @return void
     */
    public static function delete_default_local_files($name_hash, $delete_temp, $delete_log_files = true)
    {
        if ($delete_temp) {
            self::deletePackageFilesInDir($name_hash, DUPLICATOR_PRO_SSDIR_PATH_TMP, true);
        }
        self::deletePackageFilesInDir($name_hash, DUPLICATOR_PRO_SSDIR_PATH, $delete_log_files);
    }

    /**
     * Removes all files related to the namehash from the directory
     *
     * @param string $nameHash       Package namehash
     * @param string $dir            path to dir
     * @param bool   $deleteLogFiles if set to true will delete log files too
     *
     * @return void
     */
    public static function deletePackageFilesInDir($nameHash, $dir, $deleteLogFiles = false)
    {
        $globFiles = glob(SnapIO::safePath(SnapIO::untrailingslashit($dir) . "/" . $nameHash . "_*"));
        foreach ($globFiles as $globFile) {
            if (!$deleteLogFiles && SnapString::endsWith($globFile, '_log.txt')) {
                DUP_PRO_Log::trace("Skipping purge of $globFile because deleteLogFiles is false.");
                continue;
            }

            if (SnapIO::unlink($globFile)) {
                DUP_PRO_Log::trace("Successful purge of $globFile.");
            } else {
                DUP_PRO_Log::trace("Failed purge of $globFile.");
            }
        }
    }

    /**
     * Return Backup from json
     *
     * @param string  $json    json string
     * @param ?object $rowData Database row data
     *
     * @return self
     */
    protected static function get_from_json($json, $rowData = null)
    {
        $map = new JsonUnserializeMap(
            [
                ''                                           => 'cl:' . self::class,
                'Archive'                                    => 'cl:' . DUP_PRO_Archive::class,
                'Archive/Package'                            => 'rf:',
                'Archive/FileIndexManager'                   => 'cl:' . FileIndexManager::class,
                'Archive/FilterInfo'                         => 'cl:' . DUP_PRO_Archive_Filter_Info::class,
                'Archive/FilterInfo/Dirs'                    => '?cl:' . DUP_PRO_Archive_Filter_Scope_Directory::class,
                'Archive/FilterInfo/Files'                   => '?cl:' . DUP_PRO_Archive_Filter_Scope_File::class,
                'Archive/FilterInfo/Exts'                    => '?cl:' . DUP_PRO_Archive_Filter_Scope_Base::class,
                'Archive/FilterInfo/TreeSize'                => '?cl:' . DUP_PRO_Tree_files::class,
                'Multisite'                                  => 'cl:' . DUP_PRO_Multisite::class,
                'Installer'                                  => 'cl:' . DUP_PRO_Installer::class,
                'Installer/Package'                          => 'rf:',
                'Installer/origFileManger'                   => '?cl:' . SnapOrigFileManager::class,
                'Installer/configTransformer'                => '?cl:' . WPConfigTransformer::class,
                'Installer/archiveDescriptor'                => '?cl:' . ArchiveDescriptor::class,
                'Database'                                   => 'cl:' . DUP_PRO_Database::class,
                'Database/Package'                           => 'rf:',
                'Database/info'                              => 'cl:' . DatabaseInfo::class,
                'Database/info/tablesList/*'                 => 'cl:' . DescriptorDBTableInfo::class,
                'build_progress'                             => 'cl:' . BuildProgress::class,
                'build_progress/dupCreate'                   => '?cl:' . PackageDupArchiveCreateState::class,
                'build_progress/dupCreate/package'           => 'rf:',
                'build_progress/dupCreate/archiveHeader'     => 'cl:' . DupArchiveHeader::class,
                'build_progress/dupCreate/failures/*'        => 'cl:' . DupArchiveProcessingFailure::class,
                'build_progress/dupExpand'                   => '?cl:' . PackageDupArchiveExpandState::class,
                'build_progress/dupExpand/package'           => 'rf:',
                'build_progress/dupExpand/archiveHeader'     => 'cl:' . DupArchiveHeader::class,
                'build_progress/dupExpand/currentFileHeader' => '?cl:' . DupArchiveFileHeader::class,
                'build_progress/dupExpand/failures/*'        => 'cl:' . DupArchiveProcessingFailure::class,
                'db_build_progress'                          => 'cl:' . DbBuildProgress::class,
                'upload_infos/*'                             => 'cl:' . DUP_PRO_Package_Upload_Info::class,
            ]
        );

        $package = JsonSerialize::unserializeWithMap($json, $map);
        if (!$package instanceof self) {
            throw new Exception('Can\'t read json object ');
        }

        if (is_object($rowData)) {
            $reflect = new ReflectionObject($package);

            $dbValuesToProps = [
                'id'         => 'ID',
                'name'       => 'Name',
                'hash'       => 'Hash',
                'status'     => 'Status',
                'flags'      => 'flags',
                'version'    => 'version',
                'created'    => 'created',
                'updated_at' => 'updated',
            ];

            foreach ($dbValuesToProps as $dbKey => $propName) {
                if (
                    !isset($rowData->{$dbKey}) ||
                    !property_exists($package, $propName)
                ) {
                    continue;
                }

                $prop = $reflect->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($package, $rowData->{$dbKey});
            }
        }

        if (strlen($package->getVersion()) == 0) {
            $tmp              = JsonSerialize::unserialize($json);
            $package->version = $tmp['Version'];
        }
        $package->flagUpdatedAfterLoad = false; //Don't load Backup flags here to avoid infine loop, package object require deep refactoring

        return $package;
    }

    /**
     * Return true if contains non default storage
     *
     * @return bool
     */
    public function contains_non_default_storage(): bool
    {
        $defStorageId = StoragesUtil::getDefaultStorageId();
        foreach ($this->upload_infos as $upload_info) {
            if ($upload_info->getStorageId() === $defStorageId) {
                continue;
            }

            if (($storage = AbstractStorageEntity::getById($upload_info->getStorageId())) === false) {
                DUP_PRO_Log::traceError("Package refers to a storage provider that no longer exists - " . $upload_info->getStorageId());
                continue;
            }

            return true;
        }
        return false;
    }

    /**
     * Return true if package have storage type
     *
     * @param int $storage_type storage type
     *
     * @return bool
     */
    public function contains_storage_type($storage_type): bool
    {
        foreach ($this->get_storages() as $storage) {
            if ($storage->getSType() == $storage_type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if package have local storage
     *
     * @return bool
     */
    public function haveLocalStorage(): bool
    {
        foreach ($this->upload_infos as $upload_info) {
            if ($upload_info->isLocal()) {
                $filePath = SnapIO::trailingslashit($upload_info->getStorage()->getLocationString()) . $this->get_archive_filename();
                if (file_exists($filePath)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if package have remote storage
     *
     * @return bool
     */
    public function haveRemoteStorage(): bool
    {
        foreach ($this->upload_infos as $upload_info) {
            if (
                $upload_info->isRemote() &&
                $upload_info->packageExists() &&
                $upload_info->has_completed(true) &&
                !$upload_info->isDownloadFromRemote()
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all storages in which the package exists.
     * This function may also send requests to remote storages if necessary.
     *
     * @param bool $remoteOnly if true return only remote storages
     *
     * @return AbstractStorageEntity[]
     */
    public function getValidStorages($remoteOnly = false): array
    {
        $packageUpdate = false;
        $storages      = [];
        $storagesIds   = [];
        foreach ($this->upload_infos as $upload_info) {
            if (
                ($remoteOnly && !$upload_info->isRemote()) ||
                !$upload_info->packageExists() ||
                !$upload_info->has_completed(true)
            ) {
                continue;
            }
            if (($storage = AbstractStorageEntity::getById($upload_info->getStorageId())) === false) {
                continue;
            }
            if (in_array($storage->getId(), $storagesIds)) {
                continue;
            }
            if (!$storage->hasPackage($this)) {
                $upload_info->setPackageExists(false);
                $packageUpdate = true;
                continue;
            }
            $storages[]    = $storage;
            $storagesIds[] = $storage->getId();
        }
        if ($packageUpdate) {
            $this->update();
        }
        return $storages;
    }

    /**
     * Check if the package has a valid storage, meaning the backup exists in the storage.
     *
     * @return bool
     */
    public function hasValidStorage(): bool
    {
        return count($this->getValidStorages()) > 0;
    }

    /**
     * Return archive file name
     *
     * @return string
     */
    public function get_archive_filename(): string
    {
        $extension = strtolower($this->Archive->Format);
        return "{$this->getNameHash()}_archive.{$extension}";
    }

    /**
     * Get the name of the file that contains the database
     *
     * @return string
     */
    public function get_database_filename()
    {
        return $this->getNameHash() . '_database.sql';
    }

    /**
     * Get the name of the file that contains the list of directories
     *
     * @return string
     */
    public function getIndexFileName()
    {
        return $this->getNameHash() . '_index.txt';
    }

    /**
     * Get the next active Backup
     *
     * @return null|DUP_PRO_Package
     */
    public static function get_next_active_package()
    {
        $result = self::get_packages_by_status([
            'relation' => 'AND',
            [
                'op'     => '>=',
                'status' => DUP_PRO_PackageStatus::PRE_PROCESS,
            ],
            [
                'op'     => '<',
                'status' => DUP_PRO_PackageStatus::COMPLETE,
            ]
        ], 1, 0, '`id` ASC');
        if (count($result) > 0) {
            return $result[0];
        } else {
            return null;
        }
    }

    /**
     * Get the number of Backups
     *
     * @return int
     */
    public static function getNumPackages()
    {
        $ids = DUP_PRO_Package::get_ids_by_status();
        return count($ids);
    }

    /**
     * Get the number of complete Backups
     *
     * @return int
     */
    public static function getNumCompletePackages()
    {
        $ids = DUP_PRO_Package::get_ids_by_status(
            [
                [
                    'op'     => '>=',
                    'status' => DUP_PRO_PackageStatus::COMPLETE,
                ],
            ]
        );
        return count($ids);
    }

    /**
     * Quickly determine without going through the overhead of creating Backup objects
     *
     * @return bool
     */
    public static function isPackageRunning()
    {
        $ids = DUP_PRO_Package::get_ids_by_status(
            [
                [
                    'op'     => '>=',
                    'status' => DUP_PRO_PackageStatus::PRE_PROCESS,
                ],
                [
                    'op'     => '<',
                    'status' => DUP_PRO_PackageStatus::COMPLETE,
                ],
            ]
        );
        return count($ids) > 0;
    }

    /**
     * Returns true if there are packages that are in the process of being cancelled
     *
     * @return bool
     */
    public static function isPackageCancelling()
    {
        return count(self::get_pending_cancellations()) > 0;
    }

    /**
     * Check is Brand is properly prepered
     *
     * @return array<string,mixed>
     */
    public static function is_active_brand_prepared()
    {
        $manual_template = DUP_PRO_Package_Template_Entity::get_manual_template();
        if ($manual_template != null) {
            $brand = BrandEntity::getByIdOrDefault((int) $manual_template->installer_opts_brand);
            if (is_array($brand->attachments)) {
                $attachments = count($brand->attachments);
                $exists      = [];
                if ($attachments > 0) {
                    $installer = DUPLICATOR____PATH . '/installer/dup-installer/assets/images/brand';
                    if (file_exists($installer) && is_dir($installer)) {
                        foreach ($brand->attachments as $attachment) {
                            if (file_exists("{$installer}{$attachment}")) {
                                $exists[] = "{$installer}{$attachment}";
                            }
                        }
                    }
                }
                //return ($attachments == count($exists));

                return [
                    'LogoAttachmentExists' => ($attachments > 0),
                    'LogoCount'            => $attachments,
                    'LogoFinded'           => count($exists),
                    'LogoImageExists'      => ($attachments == count($exists)),
                    'LogoImages'           => $exists,
                    'Name'                 => $brand->name,
                    'Notes'                => $brand->notes,
                ];
            }
        }

        return [
            'LogoAttachmentExists' => false,
            'LogoCount'            => 0,
            'LogoFinded'           => 0,
            'LogoImageExists'      => true,
            'LogoImages'           => [],
            'Name'                 => __('Default', 'duplicator-pro'),
            'Notes'                => __('The default content used when a brand is not defined.', 'duplicator-pro'),
        ];
    }

    /**
     * Generates a scan report
     *
     * @return array<string,mixed> of scan results
     */
    public function createScanReport(): array
    {
        global $wpdb;
        $report = [];
        DUP_PRO_Log::trace('Scanning');
        try {
            $global = DUP_PRO_Global_Entity::getInstance();
            do_action('duplicator_before_scan_report', $this);

            //Set tree filters
            $this->Archive->setTreeFilters();

            //Load scan data necessary for report
            $db                        = $this->Database->getScanData();
            $timerStart                = DUP_PRO_U::getMicrotime();
            $this->ScanFile            = "{$this->getNameHash()}_scan.json";
            $report['RPT']['ScanTime'] = "0";
            $report['RPT']['ScanFile'] = $this->ScanFile;
            //FILES
            $scanPath              = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/{$this->ScanFile}";
            $dirCount              = $this->Archive->DirCount;
            $fileCount             = $this->Archive->FileCount;
            $fullCount             = $dirCount + $fileCount;
            $unreadable            = array_merge($this->Archive->FilterInfo->Files->Unreadable, $this->Archive->FilterInfo->Dirs->Unreadable);
            $site_warning_size     = $global->archive_build_mode === DUP_PRO_Archive_Build_Mode::ZipArchive ?
                DUPLICATOR_PRO_SCAN_SITE_ZIP_ARCHIVE_WARNING_SIZE : DUPLICATOR_PRO_SCAN_SITE_WARNING_SIZE;
            $filteredTables        = ($this->Database->FilterOn ? explode(',', $this->Database->FilterTables) : []);
            $subsites              = DUP_PRO_MU::getSubsites($this->Multisite->FilterSites, $filteredTables);
            $hasImportableSites    = SnapUtil::inArrayExtended($subsites, fn($subsite): bool => count($subsite->filteredTables) === 0);
            $hasNotImportableSites = SnapUtil::inArrayExtended($subsites, fn($subsite): bool => count($subsite->filteredTables) > 0);
            $hasFilteredSiteTables = $this->Database->info->tablesBaseCount !== $this->Database->info->tablesFinalCount;

            // Filtered subsites
            $filteredSites = [];
            if (is_multisite() && License::can(License::CAPABILITY_MULTISITE_PLUS)) {
                $filteredSites = array_map(
                    fn($siteId) => get_blog_details(['blog_id' => $siteId]),
                    $this->Multisite->FilterSites
                );
            }

            // Check if the user has the privileges to show the CREATE FUNCTION and CREATE PROCEDURE statements
            $privileges_to_show_create_func = true;
            $query                          = $wpdb->prepare("SHOW PROCEDURE STATUS WHERE `Db` = %s", $wpdb->dbname);
            $procedures                     = $wpdb->get_col($query, 1);
            if (count($procedures)) {
                $create                         = $wpdb->get_row("SHOW CREATE PROCEDURE `" . $procedures[0] . "`", ARRAY_N);
                $privileges_to_show_create_func = isset($create[2]);
            }

            $query     = $wpdb->prepare("SHOW FUNCTION STATUS WHERE `Db` = %s", $wpdb->dbname);
            $functions = $wpdb->get_col($query, 1);
            if (count($functions)) {
                $create                         = $wpdb->get_row("SHOW CREATE FUNCTION `" . $functions[0] . "`", ARRAY_N);
                $privileges_to_show_create_func = $privileges_to_show_create_func && isset($create[2]);
            }
            $privileges_to_show_create_func = apply_filters('duplicator_privileges_to_show_create_func', $privileges_to_show_create_func);

            //Add info to report to
            $report = [
                'Status' => 1,
                'ARC'    => [
                    'Size'                => DUP_PRO_U::byteSize($this->Archive->Size),
                    'DirCount'            => number_format($dirCount),
                    'FileCount'           => number_format($fileCount),
                    'FullCount'           => number_format($fullCount),
                    'USize'               => $this->Archive->Size,
                    'UDirCount'           => $dirCount,
                    'UFileCount'          => $fileCount,
                    'UFullCount'          => $fullCount,
                    'UnreadableDirCount'  => $this->Archive->FilterInfo->Dirs->getUnreadableCount(),
                    'UnreadableFileCount' => $this->Archive->FilterInfo->Files->getUnreadableCount(),
                    'FilterDirsAll'       => $this->Archive->FilterDirsAll,
                    'FilterFilesAll'      => $this->Archive->FilterFilesAll,
                    'FilterExtsAll'       => $this->Archive->FilterExtsAll,
                    'FilteredCoreDirs'    => $this->Archive->filterWpCoreFoldersList(),
                    'RecursiveLinks'      => $this->Archive->RecursiveLinks,
                    'UnreadableItems'     => $unreadable,
                    'FilteredSites'       => $filteredSites,
                    'Subsites'            => $subsites,
                    'Status'              => [
                        'Size'                   => $this->Archive->Size <= $site_warning_size && $this->Archive->Size >= 0,
                        'Big'                    => count($this->Archive->FilterInfo->Files->Size) <= 0,
                        'AddonSites'             => count($this->Archive->FilterInfo->Dirs->AddonSites) <= 0,
                        'UnreadableItems'        => empty($this->Archive->RecursiveLinks) && empty($unreadable),
                        'showCreateFuncStatus'   => $privileges_to_show_create_func,
                        'showCreateFunc'         => $privileges_to_show_create_func,
                        'HasImportableSites'     => $hasImportableSites,
                        'HasNotImportableSites'  => $hasNotImportableSites,
                        'HasFilteredCoreFolders' => $this->Archive->hasWpCoreFolderFiltered(),
                        'HasFilteredSiteTables'  => $hasFilteredSiteTables,
                        'HasFilteredSites'       => !empty($filteredSites),
                        'IsDBOnly'               => $this->isDBOnly(),
                        'Network'                => !$hasNotImportableSites && empty($filteredSites),
                        'PackageIsNotImportable' => !(
                            (!$hasFilteredSiteTables || $hasImportableSites) &&
                            (!$hasNotImportableSites || License::can(License::CAPABILITY_MULTISITE_PLUS))
                        ),
                    ],
                ],
                'DB'     => [
                    'Status'         => $db['Status'],
                    'SizeInBytes'    => $db['Size'],
                    'Size'           => DUP_PRO_U::byteSize($db['Size']),
                    'Rows'           => number_format($db['Rows']),
                    'TableCount'     => $db['TableCount'],
                    'TableList'      => $db['TableList'],
                    'FilteredTables' => ($this->Database->FilterOn ? explode(',', $this->Database->FilterTables) : []),
                    'DBExcluded'     => BuildComponents::isDBExcluded($this->components),
                ],
                'SRV'    => DUP_PRO_Server::getChecks($this)['SRV'],
                'RPT'    => [
                    'ScanCreated' => @date("Y-m-d H:i:s"),
                    'ScanTime'    => DUP_PRO_U::elapsedTime(DUP_PRO_U::getMicrotime(), $timerStart),
                    'ScanPath'    => $scanPath,
                    'ScanFile'    => $this->ScanFile,
                ],
            ];

            if (($json = JsonSerialize::serialize($report, JSON_PRETTY_PRINT | JsonSerialize::JSON_SKIP_CLASS_NAME)) === false) {
                throw new Exception('Problem encoding json');
            }

            if (@file_put_contents($scanPath, $json) === false) {
                throw new Exception('Problem writing scan file');
            }

            //Safe to clear at this point only JSON
            //report stores the full directory and file lists
            $this->Archive->Dirs  = [];
            $this->Archive->Files = [];
            /**
             * don't save filter info in report scan json.
             */
            $report['ARC']['FilterInfo'] = $this->Archive->FilterInfo;
            DUP_PRO_Log::trace("TOTAL SCAN TIME = " . DUP_PRO_U::elapsedTime(DUP_PRO_U::getMicrotime(), $timerStart));
        } catch (Exception $ex) {
            DUP_PRO_Log::trace("SCAN ERROR: " . $ex->getMessage());
            DUP_PRO_Log::trace("SCAN ERROR: " . $ex->getTraceAsString());
            DUP_PRO_Log::error("An error has occurred scanning the file system.", $ex->getMessage());
        }

        do_action('duplicator_after_scan_report', $this, $report);
        return $report;
    }

    /**
     * Adds file and dirs lists to scan report.
     *
     * @param string $json_path    string The path to the json file
     * @param bool   $includeLists Include the file and dir lists in the report
     *
     * @return mixed The scan report
     */
    public function getScanReportFromJson($json_path, $includeLists = false)
    {
        if (!file_exists($json_path)) {
            $message = sprintf(__("ERROR: Can't find Scanfile %s. Please ensure there no non-English characters in the Backup or schedule name.", 'duplicator-pro'), $json_path);
            throw new DUP_PRO_NoScanFileException($message);
        }

        $json_contents = file_get_contents($json_path);

        $report = json_decode($json_contents);
        if ($report === null) {
            throw new DUP_PRO_JsonDecodeException("Couldn't decode scan file.");
        }

        if ($includeLists) {
            $targetRootPath     = DUP_PRO_Archive::getTargetRootPath() === '' ? '/' : DUP_PRO_Archive::getTargetRootPath();
            $indexManager       = $this->Archive->getIndexManager();
            $report->ARC->Dirs  = $indexManager->getPathArray(FileIndexManager::LIST_TYPE_DIRS, $targetRootPath);
            $report->ARC->Files = $indexManager->getPathArray(FileIndexManager::LIST_TYPE_FILES, $targetRootPath);
        }

        return $report;
    }

    /**
     * Return Backup flags
     *
     * @return string[] ENUM of DUP_PRO_Package::FLAG_* constants
     */
    protected function getFlags()
    {
        if ($this->flagUpdatedAfterLoad == false) {
            $this->updatePackageFlags();
            $this->flagUpdatedAfterLoad = true;
        }
        return $this->flags;
    }

    /**
     * Check if package have flag
     *
     * @param string $flag flag to check, ENUM of DUP_PRO_Package::FLAG_* constants
     *
     * @return bool
     */
    public function hasFlag($flag)
    {
        return in_array($flag, $this->getFlags());
    }

    /**
     * Retur the Backup flags
     *
     * @return void
     */
    protected function updatePackageFlags()
    {
        if (empty($this->flags)) {
            switch ($this->Type) {
                case DUP_PRO_PackageType::MANUAL:
                    $this->flags[] = self::FLAG_MANUAL;
                    break;
                case DUP_PRO_PackageType::SCHEDULED:
                    $this->flags[] = self::FLAG_SCHEDULE;
                    break;
                case DUP_PRO_PackageType::RUN_NOW:
                    $this->flags[] = self::FLAG_SCHEDULE_RUN_NOW;
                    break;
            }

            if ($this->Archive->Format == 'ZIP') {
                $this->flags[] = self::FLAG_ZIP_ARCHIVE;
            } else {
                $this->flags[] = self::FLAG_DUP_ARCHIVE;
            }

            if ($this->isDBOnly()) {
                $this->flags[] = self::FLAG_DB_ONLY;
            }

            if (BuildComponents::isMediaOnly($this->components)) {
                $this->flags[] = self::FLAG_MEDIA_ONLY;
            }
        }

        $this->flags = array_diff(
            $this->flags,
            [
                self::FLAG_HAVE_LOCAL,
                self::FLAG_HAVE_REMOTE,
                self::FLAG_DISASTER_SET,
                self::FLAG_DISASTER_AVAIABLE,
            ]
        );

        if ($this->Status == DUP_PRO_PackageStatus::COMPLETE) {
            // ONLY for complete Backups
            if ($this->haveLocalStorage()) {
                $this->flags[] = self::FLAG_HAVE_LOCAL;
            }

            if ($this->haveRemoteStorage()) {
                $this->flags[] = self::FLAG_HAVE_REMOTE;
            }

            if (RecoveryPackage::getRecoverPackageId() === $this->ID) {
                $this->flags[] = self::FLAG_DISASTER_SET;
            } else {
                $status = new RecoveryStatus($this);
                if ($status->isRecoveable()) {
                    $this->flags[] = self::FLAG_DISASTER_AVAIABLE;
                }
            }
        }
    }

    /**
     * Update the Backup migration flag
     *
     * @return void
     */
    public function updateMigrateAfterInstallFlag()
    {
        $this->updatePackageFlags();
        $this->flags = array_diff(
            $this->flags,
            [self::FLAG_CREATED_AFTER_RESTORE]
        );
        $data        = MigrationMng::getMigrationData();
        // check if package id is set for old versions before 4.5.14
        if ($data->restoreBackupMode && $data->packageId > 0) {
            $installTime = strtotime($data->installTime);
            $created     = strtotime($this->created);
            if (
                $this->ID > $data->packageId && // If Backup is create after installer Backup
                $created < $installTime // But berore the installer time
            ) {
                $this->flags[] = self::FLAG_CREATED_AFTER_RESTORE;
            }
        }
        $this->flags = array_values($this->flags);
    }

    /**
     * Clean object before save
     *
     * @return void
     */
    protected function cleanObjectBeforeSave()
    {
        if ($this->Status == DUP_PRO_PackageStatus::COMPLETE || $this->Status < 0) {
            // If complete clean build progress, to clean temp data
            $this->build_progress->reset();
            $this->db_build_progress->reset();
            $this->Archive->FilterInfo->reset();
        }
    }

    /**
     *
     * @param bool $die if true die on error otherwise return true on success and false on error
     *
     * @return bool
     */
    public function save($die = true)
    {
        if ($this->ID < 1) {
            /** @var wpdb $wpdb */
            global $wpdb;

            $this->version = DUPLICATOR_PRO_VERSION;
            // Created is set in the constructor
            $this->updated = gmdate("Y-m-d H:i:s");

            $results = $wpdb->insert(
                self::getTableName(),
                [
                    'name'         => $this->Name,
                    'hash'         => $this->Hash,
                    'archive_name' => $this->get_archive_filename(),
                    'status'       => 0,
                    'progress'     => 0,
                    'flags'        => '',
                    'package'      => '',
                    'owner'        => '',
                    'version'      => $this->version,
                    'created'      => $this->created,
                    'updated_at'   => $this->updated,
                ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%f',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                ]
            );
            if ($results === false) {
                DUP_PRO_Log::trace("Problem inserting Backup: {$wpdb->last_error}");
                if ($die) {
                    DUP_PRO_Log::error("Duplicator is unable to insert a Backup record into the database table.", "'{$wpdb->last_error}'");
                }
                return false;
            }
            $this->ID = $wpdb->insert_id;
        }
        // I run the update in each case even after the insert because the saved object does not have the id
        return $this->update($die);
    }

    /**
     * Starts the Backup build process
     *
     * @param bool $closeOnEnd if true the function will close the log and die on error
     *
     * @return DUP_PRO_Package
     */
    public function run_build($closeOnEnd = true)
    {
        try {
            DUP_PRO_Log::trace('Main build step');
            global $wp_version;
            // START LOGGING
            DUP_PRO_Log::open($this->getNameHash());
            $global = DUP_PRO_Global_Entity::getInstance();
            $this->build_progress->startTimer();
            if ($this->build_progress->initialized == false) {
                DUP_PRO_Log::trace("**** START OF BUILD: " . $this->getNameHash());

                do_action('duplicator_pro_build_before_start', $this);
                $this->Installer->setFileName($this->getNameHash());
                $this->timer_start      = DUP_PRO_U::getMicrotime();
                $extension              = strtolower($this->Archive->Format);
                $this->Archive->File    = "{$this->getNameHash()}_archive.{$extension}";
                $this->Database->File   = "{$this->getNameHash()}_database.sql";
                $this->Database->DBMode = DUP_PRO_DB::getBuildMode();
                $this->ziparchive_mode  = $global->ziparchive_mode;
                if (!License::can(License::CAPABILITY_MULTISITE_PLUS)) {
                    $this->Multisite->FilterSites = [];
                }
                $php_max_time       = @ini_get("max_execution_time");
                $php_max_memory     = @ini_get('memory_limit');
                $php_max_time       = ($php_max_time == 0) ? "(0) no time limit imposed" : "[{$php_max_time}] not allowed";
                $php_max_memory     = ($php_max_memory === false) ? "Unable to set php memory_limit" : WP_MAX_MEMORY_LIMIT . " ({$php_max_memory} default)";
                $architecture       = SnapUtil::getArchitectureString();
                $clientkickoffstate = $global->clientside_kickoff ? 'on' : 'off';
                $archive_engine     = $global->get_archive_engine();
                $serverSoftware     = SnapUtil::sanitizeTextInput(INPUT_SERVER, 'SERVER_SOFTWARE', 'unknown');
                $info               = "********************************************************************************\n";
                $info              .= "********************************************************************************\n";
                $info              .= "DUPLICATOR PRO PACKAGE-LOG: " . @date("Y-m-d H:i:s") . "\n";
                $info              .= "NOTICE: Do NOT post to public sites or forums \n";
                $info              .= "PACKAGE CREATION START\n";
                $info              .= "********************************************************************************\n";
                $info              .= "********************************************************************************\n";
                $info              .= "VERSION:\t" . DUPLICATOR_PRO_VERSION . "\n";
                $info              .= "WORDPRESS:\t{$wp_version}\n";
                $info              .= "PHP INFO:\t" . phpversion() . ' | ' . 'SAPI: ' . php_sapi_name() . "\n";
                $info              .= "SERVER:\t\t{$serverSoftware} \n";
                $info              .= "ARCHITECTURE:\t{$architecture} \n";
                $info              .= "CLIENT KICKOFF: {$clientkickoffstate} \n";
                $info              .= "PHP TIME LIMIT: {$php_max_time} \n";
                $info              .= "PHP MAX MEMORY: {$php_max_memory} \n";
                $info              .= "RUN TYPE:\t" . $this->get_type_string() . "\n";
                $info              .= "MEMORY STACK:\t" . DUP_PRO_Server::getPHPMemory() . "\n";
                $info              .= "ARCHIVE ENGINE: {$archive_engine}\n";
                $info              .= "PACKAGE COMPONENTS:\n\t" . BuildComponents::displayComponentsList($this->components, ",\n\t");
                DUP_PRO_Log::infoTrace($info);
                // CREATE DB RECORD
                $this->build_progress->setBuildMode();

                if ($this->Archive->isArchiveEncrypt() && !SettingsUtils::isArchiveEncryptionAvailable()) {
                    throw new Exception("Archive encryption isn't available.");
                }

                $this->ID                          = $this->find_hash_key($this->Hash);
                $this->build_progress->initialized = true;
                if ($this->ID > 0) {
                    DUP_PRO_Log::trace("ID non zero so setting to start");
                    $this->set_status(DUP_PRO_PackageStatus::START);
                } else {
                    $this->Status = DUP_PRO_PackageStatus::START;
                    $this->save();
                }
                do_action('duplicator_pro_build_start', $this);

                if (
                    $this->Type === DUP_PRO_PackageType::SCHEDULED &&
                    !License::can(License::CAPABILITY_SCHEDULE)
                ) {
                    // Prevent scheduled backups from running if the license doesn't support it
                    throw new Exception("Can't process package schedule " . $this->ID . " because Duplicator isn't licensed");
                }
            }

            // At one point having this as an else as not part of the main logic prevented failure emails from getting sent.
            // Note2: Think that by putting has_completed() at top of check will prevent archive from continuing to build after a failure has hit.
            if ($this->build_progress->hasCompleted()) {
                $schedule = DUP_PRO_Schedule_Entity::getById($this->schedule_id);
                DUP_PRO_Log::info("\n********************************************************************************");
                DUP_PRO_Log::info("STORAGE:");
                DUP_PRO_Log::info("********************************************************************************");
                foreach ($this->upload_infos as $upload_info) {
                    if (($storage = AbstractStorageEntity::getById($upload_info->getStorageId())) === false) {
                        continue;
                    }
                    // Protection against deleted storage
                    $storage_type_string = strtoupper($storage->getStypeName());
                    $storage_path        = $storage->getLocationString();
                    DUP_PRO_Log::info($storage_type_string . ": " . $storage->getName() . ', ' . $storage_path);
                }

                if (!$this->build_progress->failed) {
                    // Only makees sense to perform build integrity check on completed archives
                    $this->build_integrity_check();
                }

                $timerEnd      = DUP_PRO_U::getMicrotime();
                $timerSum      = DUP_PRO_U::elapsedTime($timerEnd, $this->timer_start);
                $this->Runtime = $timerSum;
                // FINAL REPORT
                $info  = "\n********************************************************************************\n";
                $info .= "RECORD ID:[{$this->ID}]\n";
                $info .= "TOTAL PROCESS RUNTIME: {$timerSum}\n";
                $info .= "PEAK PHP MEMORY USED: " . DUP_PRO_Server::getPHPMemory(true) . "\n";
                $info .= "DONE PROCESSING => {$this->Name} " . @date("Y-m-d H:i:s") . "\n";
                DUP_PRO_Log::info($info);
                DUP_PRO_Log::trace("Done Backup building");

                if ($this->build_progress->failed) {
                    throw new Exception("Backup creation failed.");
                } else {
                    if ($schedule != null) {
                        //    $schedule->times_run++;
                        //                  $schedule->last_run_time     = time();
                        //                   $schedule->last_run_status   = DUP_PRO_Schedule_Entity::RUN_STATUS_SUCCESS;
                        //$schedule->save();
                        // don't send build email for success - rely on storage phase to handle that
                    }

                    //File Cleanup
                    $this->build_cleanup();
                    do_action('duplicator_pro_build_completed', $this);
                }
            } elseif (!$this->build_progress->database_script_built) {
                //START BUILD
                //PHPs serialze method will return the object, but the ID above is not passed
                //for one reason or another so passing the object back in seems to do the trick

                try {
                    if ((!$global->package_mysqldump) && ($global->package_phpdump_mode == DUP_PRO_DB::PHPDUMP_MODE_MULTI)) {
                        $this->Database->buildInChunks();
                    } else {
                        $this->Database->build();
                        $this->build_progress->database_script_built = true;
                        $this->update();
                    }
                } catch (Exception $e) {
                    do_action('duplicator_pro_build_database_fail', $this);
                    DUP_PRO_Log::infoTrace("Runtime error in database dump Message: " . $e->getMessage());
                    throw $e;
                }

                DUP_PRO_Log::trace("Done building database");
                if ($this->build_progress->database_script_built) {
                    DUP_PRO_Log::trace("Set db built for Backup $this->ID");
                }
            } elseif (!$this->build_progress->archive_built) {
                $this->Archive->buildFile($this, $this->build_progress);
                $this->update();
            } elseif (!$this->build_progress->installer_built) {
                // Note: Duparchive builds installer within the main build flow not here
                $this->Installer->build($this->build_progress);
                $this->update();
                if ($this->build_progress->failed) {
                    throw new Exception('ERROR: Problem adding installer to archive.');
                }
            }

            if ($this->build_progress->failed) {
                throw new Exception('Build progress fail');
            }
        } catch (Exception $e) {
            $message  = "Backup creation failed.\n"
                . " EXCEPTION message: " . $e->getMessage() . "\n";
            $message .= $e->getFile() . ' LINE: ' . $e->getLine() . "\n";
            $message .= $e->getTraceAsString();
            $this->buildFail($message, $closeOnEnd);
        }

        if ($closeOnEnd) {
            DUP_PRO_Log::close();
        }
        return $this;
    }

    /**
     * Backup build fail, this method die the process and set the Backup status to error
     *
     * @param string $message Error message
     * @param bool   $die     If true, the process will die
     *
     * @return void
     */
    public function buildFail($message, $die = true)
    {
        $this->build_progress->failed = true;
        $this->set_status(DUP_PRO_PackageStatus::ERROR);
        $this->post_scheduled_build_processing(0, false);
        do_action('duplicator_pro_build_fail', $this);
        DUP_PRO_Log::error($message, '', $die);
    }

    /**
     * Integriry check for the build process
     *
     * @return void
     */
    protected function build_integrity_check()
    {
        //INTEGRITY CHECKS
        //We should not rely on data set in the serlized object, we need to manually check each value
        //indepentantly to have a true integrity check.
        DUP_PRO_Log::info("\n********************************************************************************");
        DUP_PRO_Log::info("INTEGRITY CHECKS:");
        DUP_PRO_Log::info("********************************************************************************");
        //------------------------
        //SQL CHECK:  File should be at minimum 5K.  A base WP install with only Create tables is about 9K
        $sql_temp_path = SnapIO::safePath(DUPLICATOR_PRO_SSDIR_PATH_TMP . '/' . $this->Database->File);
        $sql_temp_size = @filesize($sql_temp_path);
        $sql_easy_size = DUP_PRO_U::byteSize($sql_temp_size);
        $sql_done_txt  = DUP_PRO_U::tailFile($sql_temp_path, 3);

        // Note: Had to add extra size check of 800 since observed bad sql when filter was on
        if (
            in_array(BuildComponents::COMP_DB, $this->components) &&
            (!strstr($sql_done_txt, (string) DUPLICATOR_PRO_DB_EOF_MARKER) ||
                (!$this->Database->FilterOn && $sql_temp_size < DUPLICATOR_PRO_MIN_SIZE_DBFILE_WITHOUT_FILTERS) ||
                ($this->Database->FilterOn && $this->Database->info->tablesFinalCount > 0 && $sql_temp_size < DUPLICATOR_PRO_MIN_SIZE_DBFILE_WITH_FILTERS))
        ) {
            $this->build_progress->failed = true;
            $error_text                   = "ERROR: SQL file not complete.  The file looks too small ($sql_temp_size bytes) or the end of file marker was not found.";
            $system_global                = SystemGlobalEntity::getInstance();
            if ($this->Database->DBMode == 'MYSQLDUMP') {
                $fix_text = __('Click button to switch database engine to PHP', 'duplicator-pro');
                $system_global->addQuickFix(
                    $error_text,
                    $fix_text,
                    [
                        'global' => [
                            'package_mysqldump'          => 0,
                            'package_mysqldump_qrylimit' => 32768,
                        ],
                    ]
                );
            } else {
                $fix_text = __('Click button to switch database engine to MySQLDump', 'duplicator-pro');
                $system_global->addQuickFix($error_text, $fix_text, [
                    'global' => [
                        'package_mysqldump'      => 1,
                        'package_mysqldump_path' => '',
                    ],
                ]);
            }
            DUP_PRO_Log::error("$error_text  **RECOMMENDATION: $fix_text", '', false);
            throw new Exception($error_text);
        }

        DUP_PRO_Log::info("SQL FILE: {$sql_easy_size}");
        //------------------------
        //INSTALLER CHECK:
        $exe_temp_path = SnapIO::safePath(DUPLICATOR_PRO_SSDIR_PATH_TMP . '/' . $this->Installer->getInstallerLocalName());
        $exe_temp_size = @filesize($exe_temp_path);
        $exe_easy_size = DUP_PRO_U::byteSize($exe_temp_size);
        $exe_done_txt  = DUP_PRO_U::tailFile($exe_temp_path, 10);
        if (!strstr($exe_done_txt, 'DUPLICATOR_PRO_INSTALLER_EOF') && !$this->build_progress->failed) {
            throw new Exception("ERROR: Installer file not complete.  The end of file marker was not found.  Please try to re-create the Backup.");
        }
        DUP_PRO_Log::info("INSTALLER FILE: {$exe_easy_size}");
        //------------------------
        //ARCHIVE CHECK:
        // Only performs check if we were able to obtain the count
        DUP_PRO_Log::trace("Archive file count is " . $this->Archive->file_count);
        if ($this->Archive->file_count != -1) {
            $zip_easy_size = DUP_PRO_U::byteSize($this->Archive->Size);
            if (!($this->Archive->Size)) {
                throw new Exception("ERROR: The archive file contains no size. Archive Size: {$zip_easy_size}");
            }

            $scan_filepath = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/{$this->getNameHash()}_scan.json";
            $json          = '';
            DUP_PRO_Log::trace("***********Does $scan_filepath exist?");
            if (file_exists($scan_filepath)) {
                $json = file_get_contents($scan_filepath);
            } else {
                $error_message = sprintf(__("Can't find Scanfile %s. Please ensure there no non-English characters in the Backup or schedule name.", 'duplicator-pro'), $scan_filepath);
                throw new Exception($error_message);
            }

            $scanReport         = json_decode($json);
            $expected_filecount = (int) ($scanReport->ARC->UDirCount + $scanReport->ARC->UFileCount);
            DUP_PRO_Log::info("ARCHIVE FILE: {$zip_easy_size} ");
            DUP_PRO_Log::info(sprintf(__('EXPECTED FILE/DIRECTORY COUNT: %1$s', 'duplicator-pro'), number_format($expected_filecount)));
            DUP_PRO_Log::info(sprintf(__('ACTUAL FILE/DIRECTORY COUNT: %1$s', 'duplicator-pro'), number_format($this->Archive->file_count)));
            $this->ExeSize = $exe_easy_size;
            $this->ZipSize = $zip_easy_size;
            /* ------- ZIP Filecount Check -------- */
            // Any zip of over 500 files should be within 2% - this is probably too loose but it will catch gross errors
            DUP_PRO_Log::trace("Expected filecount = $expected_filecount and archive filecount=" . $this->Archive->file_count);
            if ($expected_filecount > 500) {
                $straight_ratio = ($this->Archive->file_count > 0 ? (float) $expected_filecount / (float) $this->Archive->file_count : 0);
                // RSR NEW
                $warning_count = $scanReport->ARC->UnreadableFileCount + $scanReport->ARC->UnreadableDirCount;
                DUP_PRO_Log::trace("Unread counts) unreadfile:{$scanReport->ARC->UnreadableFileCount} unreaddir:{$scanReport->ARC->UnreadableDirCount}");
                $warning_ratio = ((float) ($expected_filecount + $warning_count)) / (float) $this->Archive->file_count;
                DUP_PRO_Log::trace("Straight ratio is $straight_ratio and warning ratio is $warning_ratio. # Expected=$expected_filecount # Warning=$warning_count and #Archive File {$this->Archive->file_count}");
                // Allow the real file count to exceed the expected by 10% but only allow 1% the other way
                if (($straight_ratio < 0.90) || ($straight_ratio > 1.01)) {
                    // Has to exceed both the straight as well as the warning ratios
                    if (($warning_ratio < 0.90) || ($warning_ratio > 1.01)) {
                        $zip_file_count = $this->Archive->file_count;
                        $error_message  = sprintf('ERROR: File count in archive vs expected suggests a bad archive (%1$d vs %2$d).', $zip_file_count, $expected_filecount);
                        if ($this->build_progress->current_build_mode == DUP_PRO_Archive_Build_Mode::Shell_Exec) {
                            // $fix_text = "Go to: Settings > Packages Tab > Archive Engine to ZipArchive.";
                            $fix_text      = __("Click on button to set archive engine to DupArchive.", 'duplicator-pro');
                            $system_global = SystemGlobalEntity::getInstance();
                            $system_global->addQuickFix(
                                $error_message,
                                $fix_text,
                                [
                                    'global' => ['archive_build_mode' => 3],
                                ]
                            );
                            $error_message .= ' **' . sprintf(__("RECOMMENDATION: %s", 'duplicator-pro'), $fix_text);
                        }

                        DUP_PRO_Log::trace($error_message);
                        throw new Exception($error_message);
                    }
                }
            }
        }
    }

    /**
     * Post scheduled build failure
     *
     * @param array<string, mixed> $tests Tests results
     *
     * @return void
     */
    public function post_scheduled_build_failure($tests = null)
    {
        $this->post_scheduled_build_processing(0, false, $tests);
    }

    /**
     * Post scheduled storage failure
     *
     * @return void
     */
    public function post_scheduled_storage_failure()
    {
        $this->post_scheduled_build_processing(1, false);
    }

    /**
     * Processes the Backup after the build
     *
     * @param int                  $stage   0 for failure at build, 1 for failure during storage phase
     * @param bool                 $success true if build was successful
     * @param array<string, mixed> $tests   Tests results
     *
     * @return void
     */
    private function post_scheduled_build_processing($stage, $success, $tests = [])
    {
        try {
            if ($this->schedule_id == -1) {
                return;
            }
            if (($schedule = DUP_PRO_Schedule_Entity::getById($this->schedule_id)) === false) {
                throw new Exception("Couldn't get schedule by ID {$this->schedule_id} to start post scheduled build processing.");
            }

            $system_global                  = SystemGlobalEntity::getInstance();
            $system_global->schedule_failed = !$success;
            $system_global->save();
            $schedule->times_run++;
            $schedule->last_run_time   = time();
            $schedule->last_run_status = ($success ? DUP_PRO_Schedule_Entity::RUN_STATUS_SUCCESS : DUP_PRO_Schedule_Entity::RUN_STATUS_FAILURE);
            $schedule->save();

            if (!empty($tests) && $tests['RES']['INSTALL'] == 'Fail') {
                $system_global->addQuickFix(
                    __('Backup was cancelled because installer files from a previous migration were found.', 'duplicator-pro'),
                    __(
                        'Click the button to remove all installer files.',
                        'duplicator-pro'
                    ),
                    [
                        'special' => ['remove_installer_files' => 1],
                    ]
                );
            }

            $this->sendBuildEmail($stage, $success);
        } catch (Exception $ex) {
            DUP_PRO_Log::trace($ex->getMessage());
        }
    }

    /**
     * Processes the Backup after the build
     *
     * @param int  $stage   0 for failure at build, 1 for failure during storage phase
     * @param bool $success true if build was successful
     *
     * @return void
     */
    protected function sendBuildEmail($stage, $success)
    {
        try {
            if ($this->buildEmailSent) {
                return;
            }

            $global = DUP_PRO_Global_Entity::getInstance();
            switch ($global->send_email_on_build_mode) {
                case DUP_PRO_Email_Build_Mode::No_Emails:
                    return;
                case DUP_PRO_Email_Build_Mode::Email_On_All_Builds:
                    break;
                case DUP_PRO_Email_Build_Mode::Email_On_Failure:
                    if ($success) {
                        return;
                    }
                    break;
                default:
                    return;
            }

            $to = !empty($global->notification_email_address) ? $global->notification_email_address : get_option('admin_email');
            if (empty($to) !== false) {
                throw new Exception("Would normally send a build notification but admin email is empty.");
            }

            if (($schedule = DUP_PRO_Schedule_Entity::getById($this->schedule_id)) === false) {
                throw new Exception("Couldn't get schedule by ID {$this->schedule_id} to start post scheduled build processing.");
            }

            DUP_PRO_Log::trace("Attempting to send build notification to $to");
            $data = [
                'success'      => $success,
                'messageTitle' => __('BACKUP SUCCEEDED', 'duplicator-pro'),
                'packageID'    => $this->ID,
                'packageName'  => $this->Name,
                'scheduleName' => $schedule->name,
                'storageNames' => array_map(fn(AbstractStorageEntity $s) => $s->getName(), $this->get_storages()),
                'packagesLink' => ControllersManager::getMenuLink(ControllersManager::PACKAGES_SUBMENU_SLUG, null, null, [], false),
                'logExists'    => file_exists($this->get_safe_log_filepath()),
            ];
            if ($success) {
                $data    = array_merge($data, [
                    'fileCount'   => $this->Archive->FileCount,
                    'packageSize' => DUP_PRO_U::byteSize($this->Archive->Size),
                    'tableCount'  => $this->Database->info->tablesFinalCount,
                    'sqlSize'     => DUP_PRO_U::byteSize($this->Database->Size),
                ]);
                $subject = sprintf(__('Backup of %1$s (%2$s) Succeeded', 'duplicator-pro'), home_url(), $schedule->name);
            } else {
                $data['messageTitle']  = __('BACKUP FAILED', 'duplicator-pro') . ' ';
                $data['messageTitle'] .= $stage !== 0
                    ? __('DURING BUILD PHASE', 'duplicator-pro')
                    : __('DURING STORAGE PHASE. CHECK SITE FOR DETAILS.', 'duplicator-pro');
                $subject               = sprintf(__('Backup of %1$s (%2$s) Failed', 'duplicator-pro'), home_url(), $schedule->name);
            }

            $message     = \Duplicator\Core\Views\TplMng::getInstance()->render("mail/scheduled-build", $data, false);
            $attachments = $data['logExists'] ? $this->get_safe_log_filepath() : '';

            if (!wp_mail($to, $subject, $message, ['Content-Type: text/html; charset=UTF-8'], $attachments)) {
                throw new Exception("Problem sending build notification to {$to} regarding Backup {$this->ID}");
            }

            $this->buildEmailSent = true;
            $this->save();
            DUP_PRO_Log::trace('wp_mail reporting send success');
        } catch (Exception | Error $ex) {
            DUP_PRO_Log::traceException($ex, "Problem sending build notification email");
        }
    }

    /**
     * Get type string
     *
     * @return string
     */
    public function get_type_string()
    {
        switch ($this->Type) {
            case DUP_PRO_PackageType::MANUAL:
                if ($this->template_id != -1) {
                    $template = DUP_PRO_Package_Template_Entity::getById($this->template_id);
                    if (isset($template->is_manual) && !$template->is_manual) {
                        return __('Template', 'duplicator-pro') . ' ' . $template->name;
                    }
                }

                return __('Manual', 'duplicator-pro');
            case DUP_PRO_PackageType::SCHEDULED:
                return __('Schedule', 'duplicator-pro');
            case DUP_PRO_PackageType::RUN_NOW:
                return __('Schedule (Run Now)', 'duplicator-pro');
            default:
                return __('Unknown', 'duplicator-pro');
        }
    }

    /**
     * Get active storage, false if none
     *
     * @return false|AbstractStorageEntity
     */
    public function get_active_storage()
    {
        if ($this->active_storage_id != -1) {
            if (($storage = AbstractStorageEntity::getById($this->active_storage_id)) === false) {
                DUP_PRO_Log::traceError("Active storage for Backup {$this->ID} is {$this->active_storage_id} but it's coming back false so resetting.");
                $this->active_storage_id = -1;
                $this->save();
            }
            return $storage;
        } else {
            return false;
        }
    }

    /**
     * Returns true if a download is in progress
     *
     * @return bool
     */
    public function isDownloadInProgress(): bool
    {
        foreach ($this->upload_infos as $upload_info) {
            if ($upload_info->isDownloadFromRemote() && $upload_info->has_completed() === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return Backup life
     *
     * @param string $type can be hours,human,timestamp
     *
     * @return int|string Backup life in hours, timestamp or human readable format
     */
    public function getPackageLife($type = 'timestamp')
    {
        $created = strtotime($this->created);
        $current = strtotime(gmdate("Y-m-d H:i:s"));
        $delta   = $current - $created;

        switch ($type) {
            case 'hours':
                return max(0, floor($delta / 60 / 60));
            case 'human':
                return human_time_diff($created, $current);
            case 'timestamp':
            default:
                return $delta;
        }
    }

    /**
     * Get Backup storages
     *
     * @return AbstractStorageEntity[]
     */
    public function get_storages(): array
    {
        $storages = [];
        foreach ($this->upload_infos as $upload_info) {
            $storage = $upload_info->getStorage();
            if ($storage instanceof UnknownStorage) {
                continue;
            }
            $storages[] = $storage;
        }
        return $storages;
    }

    /**
     * Used when we already have a Backup object that we need to make active
     *
     * @return void
     */
    public function set_temporary_package()
    {
        self::save_temporary_package($this);
    }

    /**
     *  Saves the active options associated with the active(latest) package.
     *
     *  @param mixed $post The _POST server object
     *
     *  @return void
     *
     *  @see DUP_PRO_Package::GetActive
     **/
    public static function set_manual_template_from_post($post = null)
    {
        if (isset($post)) {
            $post                  = stripslashes_deep($post);
            $mtemplate             = DUP_PRO_Package_Template_Entity::get_manual_template();
            $mtemplate->components = BuildComponents::getFromInput($post);

            if (isset($post['package_name_format'])) {
                $mtemplate->package_name_format = SnapUtil::sanitize($post['package_name_format']);
            }

            if (isset($post['filter-paths'])) {
                $post_filter_paths               = SnapUtil::sanitizeNSChars($post['filter-paths']);
                $mtemplate->archive_filter_dirs  = DUP_PRO_Archive::parseDirectoryFilter($post_filter_paths);
                $mtemplate->archive_filter_files = DUP_PRO_Archive::parseFileFilter($post_filter_paths);
            } else {
                $mtemplate->archive_filter_dirs  = '';
                $mtemplate->archive_filter_files = '';
            }

            $filter_sites = !empty($post['mu-exclude']) ? $post['mu-exclude'] : '';
            if (isset($post['filter-exts'])) {
                $post_filter_exts               = sanitize_text_field($post['filter-exts']);
                $mtemplate->archive_filter_exts = DUP_PRO_Archive::parseExtensionFilter($post_filter_exts);
            } else {
                $mtemplate->archive_filter_exts = '';
            }

            $tablelist  = isset($post['dbtables-list']) ? SnapUtil::sanitizeNSCharsNewlineTrim($post['dbtables-list']) : '';
            $compatlist = isset($post['dbcompat']) ? implode(',', $post['dbcompat']) : '';
            // PACKAGE
            // Replaces any \n \r or \n\r from the Backup notes
            if (isset($post['package-notes'])) {
                $mtemplate->notes = SnapUtil::sanitizeNSCharsNewlineTrim($post['package-notes']);
            } else {
                $mtemplate->notes = '';
            }

            //MULTISITE
            $mtemplate->filter_sites = $filter_sites;
            //ARCHIVE
            $mtemplate->archive_filter_on    = isset($post['filter-on']) ? 1 : 0;
            $mtemplate->archive_filter_names = isset($post['filter-names']);
            //INSTALLER
            $secureOn = (isset($post['secure-on']) ? (int) $post['secure-on'] : ArchiveDescriptor::SECURE_MODE_NONE);
            switch ($secureOn) {
                case ArchiveDescriptor::SECURE_MODE_NONE:
                case ArchiveDescriptor::SECURE_MODE_INST_PWD:
                case ArchiveDescriptor::SECURE_MODE_ARC_ENCRYPT:
                    $mtemplate->installer_opts_secure_on = $secureOn;
                    break;
                default:
                    throw new Exception(__('Select valid secure mode', 'duplicator-pro'));
            }

            $mtemplate->installerPassowrd = isset($post['secure-pass']) ? SnapUtil::sanitizeNSCharsNewlineTrim($post['secure-pass']) : '';
            //BRAND
            $mtemplate->installer_opts_brand     = ((isset($post['installer_opts_brand']) && (int) $post['installer_opts_brand'] > 0) ? (int) $post['installer_opts_brand'] : -1);
            $mtemplate->installer_opts_skip_scan = (isset($post['skipscan']) && 1 == $post['skipscan']) ? 1 : 0;
            //cPanel
            $mtemplate->installer_opts_cpnl_enable    = (isset($post['installer_opts_cpnl_enable']) && 1 == $post['installer_opts_cpnl_enable']) ? 1 : 0;
            $mtemplate->installer_opts_cpnl_host      = isset($post['installer_opts_cpnl_host']) ? sanitize_text_field($post['installer_opts_cpnl_host']) : '';
            $mtemplate->installer_opts_cpnl_user      = isset($post['installer_opts_cpnl_user']) ? sanitize_text_field($post['installer_opts_cpnl_user']) : '';
            $mtemplate->installer_opts_cpnl_db_action = isset($post['installer_opts_cpnl_db_action']) ? sanitize_text_field($post['installer_opts_cpnl_db_action']) : '';
            $mtemplate->installer_opts_cpnl_db_host   = isset($post['installer_opts_cpnl_db_host']) ? sanitize_text_field($post['installer_opts_cpnl_db_host']) : '';
            $mtemplate->installer_opts_cpnl_db_name   = isset($post['installer_opts_cpnl_db_name']) ? sanitize_text_field($post['installer_opts_cpnl_db_name']) : '';
            $mtemplate->installer_opts_cpnl_db_user   = isset($post['installer_opts_cpnl_db_user']) ? sanitize_text_field($post['installer_opts_cpnl_db_user']) : '';
            //Basic
            $mtemplate->installer_opts_db_host = isset($post['installer_opts_db_host']) ? sanitize_text_field($post['installer_opts_db_host']) : '';
            $mtemplate->installer_opts_db_name = isset($post['installer_opts_db_name']) ? sanitize_text_field($post['installer_opts_db_name']) : '';
            $mtemplate->installer_opts_db_user = isset($post['installer_opts_db_user']) ? sanitize_text_field($post['installer_opts_db_user']) : '';
            // DATABASE
            $mtemplate->database_filter_on      = isset($post['dbfilter-on']) ? 1 : 0;
            $mtemplate->databasePrefixFilter    = isset($post['db-prefix-filter']);
            $mtemplate->databasePrefixSubFilter = isset($post['db-prefix-sub-filter']);
            $mtemplate->database_filter_tables  = sanitize_text_field($tablelist);

            $mtemplate->database_compatibility_modes = $compatlist;
            $mtemplate->save();
        }
    }

    /**
     *
     * @param int   $template_id Template ID
     * @param int[] $storage_ids Array of storage IDs
     *
     * @return self|null Retunr Backup or null if manual template don't exists
     */
    public static function set_temporary_package_from_template_and_storages($template_id, $storage_ids)
    {
        // Use the manual template for the data while the $template_id is used just to record where it originally came from
        $manual_template = DUP_PRO_Package_Template_Entity::get_manual_template();
        if ($manual_template != null) {
            $package = new self(
                DUP_PRO_PackageType::MANUAL,
                $storage_ids,
                $manual_template
            );

            //PACKAGE
            $package->notes = $manual_template->notes;
            // this is the template set in manual mode
            $package->template_id = $template_id;

            $system_global = SystemGlobalEntity::getInstance();
            $system_global->clearFixes();
            $system_global->package_check_ts = 0;
            $system_global->save();
            self::save_temporary_package($package);
            return $package;
        } else {
            DUP_PRO_Log::trace("Manual template doesn't exist!");
            return null;
        }
    }

    /**
     * Save temporary Backup
     *
     * @return void
     */
    protected function saveTemporary()
    {
        $this->cleanObjectBeforeSave();
        $this->version = DUPLICATOR_PRO_VERSION;
        $this->updated = gmdate("Y-m-d H:i:s");
        update_option(
            self::OPT_ACTIVE,
            JsonSerialize::serialize(
                $this,
                JSON_PRETTY_PRINT | JsonSerialize::JSON_SKIP_CLASS_NAME
            )
        );
    }

    /**
     * save Backup on OPT_ACTIVE after clean
     *
     * @param self $package Backup to save
     * @param bool $clone   if true clone obkect before clean to prevent original obj modificantion
     *
     * @return void
     */
    protected static function save_temporary_package($package, $clone = true)
    {
        if ($clone) {
            $cleanPack = clone $package;
        } else {
            $cleanPack = $package;
        }
        $cleanPack->saveTemporary();
    }

    /**
     * Delete temporary Backup
     *
     * @return void
     */
    public static function delete_temporary_package()
    {
        delete_option(self::OPT_ACTIVE);
    }

    /**
     * Save any property of this class through reflection
     *
     * @param string $property A valid public property in this class
     * @param mixed  $value    The value for the new dynamic property
     *
     * @return void
     */
    public static function set_temporary_package_member($property, $value)
    {

        $package = self::get_temporary_package();
        if ($property == 'Status') {
            do_action('duplicator_pro_package_before_set_status', $package, $value);
        }
        $reflectionClass = new ReflectionClass($package);
        $reflectionClass->getProperty($property)->setValue($package, $value);
        self::save_temporary_package($package, false);
        if ($property == 'Status') {
            do_action('duplicator_pro_package_after_set_status', $package, $value);
        }
    }

    /**
     *  Sets the status to log the state of the build
     *
     *  @param int $status The status level for where the Backup is
     *
     *  @return void
     */
    public function set_status($status)
    {
        if (!is_numeric($status) || $status < -6 || $status > 100) {
            DUP_PRO_Log::error("Package SetStatus did not receive a proper code.");
        }

        // execute hooks only if status has changed
        $doHook = ($this->Status !== $status);
        if ($doHook) {
            do_action('duplicator_pro_package_before_set_status', $this, $status);
        }
        $this->Status = $status;
        $this->update(); // alwais update Backup
        if ($doHook) {
            do_action('duplicator_pro_package_after_set_status', $this, $status);
        }
    }

    /**
     * update Backup in database
     *
     * @param bool $die if true die on error otherwise return true on success and false on error
     *
     * @return bool
     */
    public function update($die = true): bool
    {
        /** @var wpdb $wpdb */
        global $wpdb;
        global $current_user;

        $this->cleanObjectBeforeSave();
        $this->updatePackageFlags();
        $this->version = DUPLICATOR_PRO_VERSION;
        $this->updated = gmdate("Y-m-d H:i:s");

        $packageObj = JsonSerialize::serialize($this, JSON_PRETTY_PRINT | JsonSerialize::JSON_SKIP_CLASS_NAME);
        if (!$packageObj) {
            if ($die) {
                DUP_PRO_Log::error("Package SetStatus was unable to serialize Backup object while updating record.");
            }
            return false;
        }
        $wpdb->flush();
        if (
            $wpdb->update(
                self::getTableName(),
                [
                    'name'         => $this->Name,
                    'hash'         => $this->Hash,
                    'archive_name' => $this->get_archive_filename(),
                    'status'       => (int) $this->Status,
                    'progress'     => $this->Status,
                    'flags'        => implode(',', $this->flags),
                    'package'      => $packageObj,
                    'owner'        => $current_user->user_login ?? 'unknown',
                    'version'      => $this->version,
                    'created'      => $this->created,
                    'updated_at'   => $this->updated,
                ],
                ['ID' => $this->ID],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%f',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                ],
                ['%d']
            ) === false
        ) {
            if ($die) {
                DUP_PRO_Log::error("Database update error: " . $wpdb->last_error);
            }
            return false;
        }

        return true;
    }

    /**
     * Does a hash already exists
     *
     * @param string $hash The hash to check
     *
     * @return int Returns 0 if no has is found, if found returns the table ID
     */
    public function find_hash_key($hash)
    {
        global $wpdb;
        $table = self::getTableName();
        $sql   = $wpdb->prepare("SELECT ID, hash FROM `{$table}` WHERE hash = %s", $hash);
        $qry   = $wpdb->get_row($sql);
        if (strlen($qry->hash) == 0) {
            return 0;
        } else {
            return $qry->ID;
        }
    }

    /**
     *  Makes the hashkey for the Backup files
     *
     *  @return string A unique hashkey
     */
    protected function make_hash()
    {
        // IMPORTANT!  Be VERY careful in changing this format - the FTP delete logic requires 3 segments with the last segment to be the date in YmdHis format.
        try {
            $date = date(self::PACKAGE_HASH_DATE_FORMAT, strtotime($this->created));
            if (function_exists('random_bytes')) {
                // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.random_bytesFound
                $rand = (string) random_bytes(8);
                return bin2hex($rand) . mt_rand(1000, 9999) . '_' . $date;
            } else {
                return strtolower(md5(uniqid((string) random_int(0, mt_getrandmax()), true))) . '_' . $date;
            }
        } catch (Exception $exc) {
            return strtolower(md5(uniqid((string) random_int(0, mt_getrandmax()), true))) . '_' . $date;
        }
    }

    /**
     * Gets the active Backup.  The active Backup is defined as the Backup that was lasted saved.
     * Do to cache issues with the built in WP function get_option moved call to a direct DB call.
     *
     * @see DUP_PRO_Package::SaveActive
     *
     * @param bool $create_if_not_exists if true create a new Backup if not exists
     *
     * @return ?DUP_PRO_Package Retur Backup or null on failure
     */
    public static function get_temporary_package($create_if_not_exists = true)
    {

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
                self::OPT_ACTIVE
            )
        );
        if (is_object($row)) {
            return self::get_from_json($row->option_value);
        } elseif ($create_if_not_exists) {
            return new DUP_PRO_Package();
        } else {
            return null;
        }
    }

    /**
     * Creates a default name
     *
     * @param bool $preDate if true prepend date to name
     *
     * @return string Default Backup name
     */
    public static function get_default_name($preDate = true)
    {
        //Remove specail_chars from final result
        $special_chars = [
            ".",
            "-",
        ];
        $name          = ($preDate) ? date('Ymd') . '_' . sanitize_title(get_bloginfo('name', 'display')) : sanitize_title(get_bloginfo('name', 'display')) . '_' . date('Ymd');
        $name          = substr(sanitize_file_name($name), 0, 40);
        return str_replace($special_chars, '', $name);
    }

    /**
     * Generate a Backup name from a template
     *
     * @param ?DUP_PRO_Package_Template_Entity $template  Template to use
     * @param int                              $timestamp Timestamp
     *
     * @return string
     */
    protected function getNameFromFormat(
        ?DUP_PRO_Package_Template_Entity $template = null,
        $timestamp = 0
    ) {
        $nameFormat = new NameFormat();
        $nameFormat->setTimestamp($timestamp);
        $nameFormat->setScheduleId($this->schedule_id);
        if ($template instanceof DUP_PRO_Package_Template_Entity) {
            $nameFormat->setFormat($template->package_name_format);
            $nameFormat->setTemplateId($template->getId());
        }
        return $nameFormat->getName();
    }

    /**
     * Safe tmp cleanup
     *
     * @param bool $purge_temp_archives if true purge temp archives
     *
     * @return void
     */
    public static function safe_tmp_cleanup($purge_temp_archives = false)
    {
        if ($purge_temp_archives) {
            $dir = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/*_archive.zip.*";
            foreach (glob($dir) as $file_path) {
                unlink($file_path);
            }
            $dir = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/*_archive.daf.*";
            foreach (glob($dir) as $file_path) {
                unlink($file_path);
            }
        } else {
            $dir   = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/*";
            $files = glob($dir);
            if ($files !== false) {
                foreach ($files as $file_path) {
                    if (basename($file_path) === 'index.php') {
                        continue;
                    }
                    if (filemtime($file_path) <= time() - DUP_PRO_Constants::TEMP_CLEANUP_SECONDS) {
                        SnapIO::rrmdir($file_path);
                    }
                }
            }
        }
    }

    /**
     *  Cleanup all tmp files
     *
     *  @param bool $all empty all contents
     *
     *  @return bool true on success fail on failure
     */
    public static function tmp_cleanup($all = false): bool
    {
        //Delete all files now
        if ($all) {
            $dir = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/*";
            foreach (glob($dir) as $file) {
                if (basename($file) === 'index.php') {
                    continue;
                }
                SnapIO::rrmdir($file);
            }
        } else {
            // Remove scan files that are 24 hours old
            $dir = DUPLICATOR_PRO_SSDIR_PATH_TMP . "/*_scan.json";
            foreach (glob($dir) as $file) {
                if (filemtime($file) <= time() - DUP_PRO_Constants::TEMP_CLEANUP_SECONDS) {
                    SnapIO::rrmdir($file);
                }
            }
        }

        // Clean up extras directory if it is still hanging around
        $extras_directory = SnapIO::safePath(DUPLICATOR_PRO_SSDIR_PATH_TMP) . '/extras';
        if (file_exists($extras_directory)) {
            try {
                if (!SnapIO::rrmdir($extras_directory)) {
                    throw new Exception('Failed to delete: ' . $extras_directory);
                }
            } catch (Exception $ex) {
                DUP_PRO_Log::trace("Couldn't recursively delete {$extras_directory}");
            }
        }

        return true;
    }

    /**
     * Build cleanup
     *
     * @return void
     */
    private function build_cleanup()
    {
        $files = SnapIO::regexGlob(DUPLICATOR_PRO_SSDIR_PATH_TMP);
        if (count($files) > 0) {
            $filesToStore = [
                $this->Installer->getInstallerLocalName(),
                $this->Archive->File,
            ];
            $newPath      = DUPLICATOR_PRO_SSDIR_PATH;

            foreach ($files as $file) {
                $fileName = basename($file);

                if (!strstr($fileName, $this->getNameHash())) {
                    continue;
                }

                if (in_array($fileName, $filesToStore)) {
                    if (function_exists('rename')) {
                        rename($file, "{$newPath}/{$fileName}");
                    } elseif (function_exists('copy')) {
                        copy($file, "{$newPath}/{$fileName}");
                    } else {
                        throw new Exception('copy and rename function don\'t found');
                    }
                }

                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
        $this->set_status(DUP_PRO_PackageStatus::COPIEDPACKAGE);
    }

    /**
     *  Provides various date formats
     *
     *  @param string $utcDate created date in the GMT timezone
     *  @param int    $format  Various date formats to apply
     *
     *  @return string formatted date
     */
    public static function format_and_get_local_date_time($utcDate, $format = 1)
    {
        $date = get_date_from_gmt($utcDate);
        $date = new DateTime($date);
        switch ($format) {
            //YEAR
            case 1:
                return $date->format('Y-m-d H:i');
            case 2:
                return $date->format('Y-m-d H:i:s');
            case 3:
                return $date->format('y-m-d H:i');
            case 4:
                return $date->format('y-m-d H:i:s');
                //MONTH
            case 5:
                return $date->format('m-d-Y H:i');
            case 6:
                return $date->format('m-d-Y H:i:s');
            case 7:
                return $date->format('m-d-y H:i');
            case 8:
                return $date->format('m-d-y H:i:s');
                //DAY
            case 9:
                return $date->format('d-m-Y H:i');
            case 10:
                return $date->format('d-m-Y H:i:s');
            case 11:
                return $date->format('d-m-y H:i');
            case 12:
                return $date->format('d-m-y H:i:s');
            default:
                return $date->format('Y-m-d H:i');
        }
    }

    /**
     * Get the backup's descriptor manager
     *
     * @return InstallerDescriptors The descriptor manager
     */
    public function getDescriptorMng()
    {
        if (is_null($this->descriptorsMng)) {
            $this->descriptorsMng = new InstallerDescriptors(
                $this->getPackageHash(),
                date(self::PACKAGE_HASH_DATE_FORMAT, strtotime($this->created))
            );
        }

        return $this->descriptorsMng;
    }

    /**
     * Get package hash
     *
     * @return string Backup hash
     */
    public function getPackageHash()
    {
        $archiveInfo = ArchiveDescriptor::getArchiveNameParts($this->get_archive_filename());
        return $archiveInfo['packageHash'];
    }

    /**
     * Get secondary Backup hash
     *
     * @return string Backup hash
     */
    public function getSecondaryPackageHash()
    {
        $newHash    = $this->make_hash();
        $hashParts  = explode('_', $newHash);
        $firstPart  = substr($hashParts[0], 0, 7);
        $hashParts  = explode('_', $this->Hash);
        $secondPart = substr($hashParts[1], -8);
        return $firstPart . '-' . $secondPart;
    }

    /**
     * Get hash from backup archive filename
     *
     * @param string $archiveName Archive filename
     *
     * @return ?DUP_PRO_Package Return Backup or null on failure
     */
    public static function getByArchiveName($archiveName)
    {
        global $wpdb;
        if (!preg_match(DUPLICATOR_PRO_ARCHIVE_REGEX_PATTERN, $archiveName, $matches)) {
            return null;
        }

        $table = self::getTableName();
        $sql   = $wpdb->prepare("SELECT * FROM `{$table}` where archive_name = %s", $archiveName);
        $row   = $wpdb->get_row($sql);
        if ($row) {
            return self::package_from_row($row);
        } else {
            return null;
        }
    }

    /**
     * Check if backup transfer is interrupted
     *
     * @return bool returns true if Backup transfer was canceled or failed
     */
    public function transferWasInterrupted(): bool
    {
        $recentUploadInfos = self::getRecentUploadInfos();
        foreach ($recentUploadInfos as $recentUploadInfo) {
            if ($recentUploadInfo->isFailed() || $recentUploadInfo->isCancelled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get recent unique $uploadInfos with giving highest priority to the latest one uploadInfo
     * if two or more uploadInfo of the same storage type exists
     *
     * @return DUP_PRO_Package_Upload_Info[]
     */
    private function getRecentUploadInfos(): array
    {
        $uploadInfos    = [];
        $tempStorageIds = [];
        foreach (array_reverse($this->upload_infos) as $upload_info) {
            if (!in_array($upload_info->getStorageId(), $tempStorageIds)) {
                $tempStorageIds[] = $upload_info->getStorageId();
                $uploadInfos[]    = $upload_info;
            }
        }
        return $uploadInfos;
    }

    /**
     * Get Backup table name
     *
     * @return string
     */
    public static function getTableName()
    {
        global $wpdb;
        return $wpdb->base_prefix . "duplicator_backups";
    }

    /**
     * Get version of Backups stored in DB
     *
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Get created date
     *
     * @return string
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Init entity table
     *
     * @return string[] Strings containing the results of the various update queries.
     */
    final public static function initTable()
    {
        /** @var wpdb $wpdb */
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $tableName       = self::getTableName();

        $flags = [
            self::FLAG_MANUAL,
            self::FLAG_SCHEDULE,
            self::FLAG_SCHEDULE_RUN_NOW,
            self::FLAG_DB_ONLY,
            self::FLAG_MEDIA_ONLY,
            self::FLAG_HAVE_LOCAL,
            self::FLAG_HAVE_REMOTE,
            self::FLAG_DISASTER_AVAIABLE,
            self::FLAG_DISASTER_SET,
            self::FLAG_CREATED_AFTER_RESTORE,
            self::FLAG_ACTIVE,
            self::FLAG_TEMPLATE,
            self::FLAG_ZIP_ARCHIVE,
            self::FLAG_DUP_ARCHIVE,
        ];

        $flagsStr = array_map(fn($flag): string => "'{$flag}'", $flags);
        $flagsStr = implode(',', $flagsStr);

        // PRIMARY KEY must have 2 spaces before for dbDelta to work
        // Mysql 5.5 can't have more than 1 DEFAULT CURRENT_TIMESTAMP
        $sql = <<<SQL
CREATE TABLE `{$tableName}` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(250) NOT NULL,
    `hash` varchar(50) NOT NULL,
    `archive_name` varchar(350) NOT NULL DEFAULT '',
    `status` int(11) NOT NULL,
    `progress` float NOT NULL DEFAULT 0,
    `flags` set({$flagsStr}) NOT NULL DEFAULT '',
    `package` longtext NOT NULL,
    `owner` varchar(60) NOT NULL DEFAULT '',
    `version` varchar(30) NOT NULL DEFAULT '',
    `created` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (`id`),
    KEY `hash` (`hash`),
    KEY `flags` (`flags`),
    KEY `version` (`version`),
    KEY `created` (`created`),
    KEY `updated_at` (`updated_at`),
    KEY `status` (`status`),
    KEY `name` (`name`(191)),
    KEY `archive_name` (`archive_name`(191))
) {$charset_collate};
SQL;

        return SnapWP::dbDelta($sql);
    }
}
