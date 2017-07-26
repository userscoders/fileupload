<?php
namespace filemanager;
/**
 * This behavior manage a file associated to a model attribute of a CActiveRecord.
 * It will write an uploaded file after saving a model if one is provided,
 * and delete it after removing the model from db.
 * The file name will be calculated with attribute(s) of the model.
 *
 * You can create multiple files from the one sended by the user, by using formats,
 * and use a processor to apply some process on each format.
 * Each format will create a file with a unique suffix in the file name.
 *
 * For an example, see example file.
 */
use \Yii;
use yii\base\Behavior;
use yii\helpers\Html;
use yii\web\UploadedFile;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;

class FileARBehavior extends Behavior
{
    /**
     * @var UploadedFile
     */
    public $file;
    /**
     * Where to add last modified time has to file url to prevent caching old file versions by browser
     * @var bool
     */
    public $addTimeHashToUrl = true;
    /**
     * this attribute (or array of attributes) will determine a part of the file name. Default is model primary key(s).
     */
    public $attributeForName;

    /**
     * the attribute filled by a file on the form. Must be set.
     */
    public $attribute;

    /**
     * possible extensions of the file name, comma separated. If empty - all file extensions possible
     */
    public $extension = null;

    /**
     * without the first and last /, the folder where to put the image relative to webroot. must be set.
     */
    public $relativeWebRootFolder;

    /**
     * name of the default file if needed.
     */
    public $defaultName;

    /**
     * file prefix. can be used to avoid name clashes for example.
     */
    public $prefix = '';

    /**
     * default separator when attributeForName is an array and must be joined.
     */
    public $attributeSeparator = '_';

    /**
     * key => value which list all the desired formats. can be null (the 'normal' => _normalFormat is used then)
     * example:
     * 'thumb' => [
     *   'suffix' => '_thumb',
     *   'process' => ['resize' => [60, 60]]
     * ]
     */
    public $formats = [];

    /**
     * An yii path to a class used to process your file(s).
     * Must at least have a constructor with one parameter (the temp file path) and one
     * method save() wich takes an argument, the new file path.
     * Every function in the class could then be invoked from the 'process' key of a given format.
     */
    public $processor;

    /**
     * Force the extension of all saved files. Default to null,
     * which means use the source extension.
     */
    public $forceExt;

    // normal format
    private static $_normalFormat = ['suffix' => '', 'process' => []];

    private $_fileName;

    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_DELETE=>'afterDelete',
            ActiveRecord::EVENT_AFTER_UPDATE=>'afterUpdate',
        ];
    }

    // override to init some things
    public function setEnabled($enable)
    {
        parent::setEnabled($enable);
        if (!$enable) return;
        if (empty($this->attribute)) throw new \Exception('Attribute property must be set.');

        if (array_key_exists('normal', $this->formats))
            $this->formats['normal'] = array_merge(self::$_normalFormat, $this->formats['normal']);
        else
            $this->formats['normal'] = self::$_normalFormat;
        // set suffixes if not defined
        foreach ($this->formats as $name => $f) {
            if (!array_key_exists('suffix', $f)) $f['suffix'] = $name;
        }
    }

    /**
     * return an array with entries like format => file_path when file_path exists
     */
    public function getFilesPath()
    {
        $path = $this->getFolderPath();
        $fname = $this->getFileName();
        $fs = $this->getAnyExistingFilesName($path, $fname);
        $len = strlen($path) + strlen($fname) + 1;
        $res = [];
        foreach ($fs as $f) {
            foreach ($this->formats as $foName => $fo) {
                $s = $fo['suffix'];
                if (empty($s) || strpos($f, $s, $len)) $res[$foName] = $f;
            }
        }
        return $res;
    }

    /**
     * return the file path corresponding to the format, or null if file does not exists.
     */
    public function getFilePath($format = 'normal')
    {
        $fs = $this->getFilesPath();
        return isset($fs[$format]) ? $fs[$format] : null;
    }

    /**
     * get the file name without extension and without suffix
     */
    protected function getFileName()
    {
        if (!isset($this->_fileName)) {
            if (!isset($this->attributeForName)) $this->attributeForName = $this->owner->tableSchema->primaryKey;
            if (!is_array($this->attributeForName)) $partName = $this->owner->{$this->attributeForName};
            else {
                $partName = [];
                foreach ($this->attributeForName as $attr) $partName[] = $this->owner->{$attr};
                $partName = join($this->attributeSeparator, $partName);
            }
            $this->_fileName = $this->prefix . $partName;
        }
        return $this->_fileName;
    }

    /**
     * get the default file name without extension and suffix
     */
    public function getDefaultFileName()
    {
        return $this->prefix . $this->defaultName;
    }

    /**
     * get the path folder of the stored files
     */
    protected function getFolderPath()
    {
        return Yii::getAlias('@webroot'). DIRECTORY_SEPARATOR . $this->relativeWebRootFolder;
    }

    /*
      * get existing files matching fname in path $path whith glob (and GLOB_BRACE).
      */
    protected function getExistingFilesName($path, $fname)
    {
        $res = glob($path . DIRECTORY_SEPARATOR . $fname .
            (!empty($this->extension)?'.{' . str_replace(' ', '', $this->extension) . '}':'.*')
            , GLOB_NOSORT | GLOB_BRACE);
        if ($res === false) $res = [];
        return $res;
    }

    /*
      * get existing files matching fname with all suffixes
      */
    protected function getAnyExistingFilesName($path, $fname)
    {
        $suffixes = [];
        foreach ($this->formats as $f) {

            if(isset($f['suffix'])){
                $s = $f['suffix'];

                if (!empty($f)) $suffixes[] = $s;
            }else
                continue;
        }

        // this use the glob GLOB_BRACE option
        return $this->getExistingFilesName($path, $fname . '{' . join(',', $suffixes) . '}');
    }

    /**
     * Try to get a file url for the given suffix, using getFileName or getDefaultFileName.
     */
    protected function getFileUrlWithSuffix($suffix)
    {
        $path = $this->getFolderPath();
        $fs = $this->getExistingFilesName($path, $this->getFileName() . $suffix);
        if (!empty($fs)) return $this->makeFileUrl($fs[0]);
        if (!isset($this->defaultName)) return null;
        $fs = $this->getExistingFilesName($path, $this->getDefaultFileName() . $suffix);
        if (!empty($fs)) return $this->makeFileUrl($fs[0]);
        return null;
    }

    /**
     * Retrieve url for a given format.
     */
    public function getFileUrl($format = 'normal')
    {
        return $this->getFileUrlWithSuffix($this->formats[$format]['suffix']);
    }

    /**
     * Instanciate a processor.
     */
    protected function createProcessor($klass, $srcPath)
    {
        return new $klass($srcPath);
    }

    /**
     * apply some processing to $processor. each key of $option must be a method of $processor clkass.
     */
    protected function process($processor, $options)
    {
        foreach ($options as $method => $args) {
            call_user_func_array([$processor, $method], is_array($args) ? $args : []);
        }
    }

    /**
     * Save an uploaded file if given, after removing possible other files.
     */
    public function afterInsert($event)
    {

        if (isset($this->file))
            $file = $this->file;
        else
            $file = UploadedFile::getInstance($this->owner, $this->attribute);

        if(!empty($this->extension) && isset($file))
            $valid = strpos(strtolower($this->extension), strtolower($file->extension)) !== false;
        else
            $valid = true;
        if ($file && $valid) {
            $path = $this->getFolderPath();
            $fname = $this->getFileName();
            $this->deleteFile($path, $fname);
            $this->saveFile($file, $path, $fname, $file->extension);
        }

    }

    public function afterUpdate($event){
        $this->afterInsert($event);
    }

    /**
     * Save every files, after processing them if needed.
     * @param $file UploadedFile
     * @param $filePath
     * @param $fileName
     * @param $ext
     * @return void
     */
    protected function saveFile($file, $filePath, $fileName, $ext)
    {
        $klass = null;
        if (isset($this->processor)) {
//            Yii::import($this->processor);
            $tmp = strrchr($this->processor, '.');
            $klass = ($tmp !== false) ? substr($tmp, 1) : $this->processor;
        }

        $path = $filePath . DIRECTORY_SEPARATOR . $fileName;
        $real_ext = '.' . strtolower((isset($this->forceExt) ? $this->forceExt : $ext));

        // optimize if we have only normal format and no processor
        if (count($this->formats) == 1 && !isset($klass)) {
            $file->saveAs($path . $real_ext);
        } else if (isset($klass)) {
            // create a file for each format
            foreach ($this->formats as $f) {
                $processor = $this->createProcessor($klass, $file->tempName);
                if(isset($f['suffix'])){
                    if (!empty($f['process'])) $this->process($processor, $f['process']);
                    $processor->save($path . $f['suffix'] . $real_ext);
                }else
                    continue;
            }
        } else {
            // I don't know if it is usefull... There's no processor and multiple formats,
            // ie it is a simple copy multiple times with different suffixes.
            // maybe I have to throw an exception instead.
            foreach ($this->formats as $f) $file->saveAs($path . $f['suffix'] . $real_ext, false);
        }
    }

    /**
     * Delete the files on delete.
     */
    public function afterDelete($event)
    {
        $this->deleteFile($this->getFolderPath(), $this->getFileName());
    }

	public function deleteFiles(){
		$this->deleteFile($this->getFolderPath(), $this->getFileName());
	}

    /**
     * Delete the files retrieved by getExistingFilesName
     */
    protected function deleteFile($path, $fname)
    {
        $fs = $this->getAnyExistingFilesName($path, $fname);
        if (!empty($fs)) { //todo: check why $fs can be empty
            foreach ($fs as $f) unlink($f);
        }
    }

    private function makeFileUrl($fileName)
    {
        if ($this->addTimeHashToUrl) {
            $time = filemtime($fileName);
            $suffix = '?_=' . crc32($time);
        } else {
            $suffix = '';
        }
        return Yii::$app->urlManager->baseUrl. '/' . $this->relativeWebRootFolder . '/' . basename($fileName) . $suffix;
    }
}