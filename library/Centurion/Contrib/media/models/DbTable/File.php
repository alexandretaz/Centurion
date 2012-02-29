<?php
/**
 * Centurion
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@centurion-project.org so we can send you a copy immediately.
 *
 * @category    Centurion
 * @package     Centurion_Contrib
 * @subpackage  Media
 * @copyright   Copyright (c) 2008-2011 Octave & Octave (http://www.octaveoctave.com)
 * @license     http://centurion-project.org/license/new-bsd     New BSD License
 * @version    $Id$
 */

/**
 * @category    Centurion
 * @package     Centurion_Contrib
 * @subpackage  Media
 * @copyright   Copyright (c) 2008-2011 Octave & Octave (http://www.octaveoctave.com)
 * @license     http://centurion-project.org/license/new-bsd     New BSD License
 * @author      Florent Messa <florent.messa@gmail.com>
 * @author      Mathias Desloges <m.desloges@gmail.com>
 * @author      Laurent Chenay <lc@octaveoctave.com>
 */
class Media_Model_DbTable_File extends Centurion_Db_Table_Abstract
{
    const BELONG_TO = 'belong_to';

    protected $_primary = 'id';

    protected $_name = 'media_file';

    protected $_rowClass = 'Media_Model_DbTable_Row_File';

    protected $_selectClass = 'Media_Model_DbTable_Select_File';

    protected $_meta = array('verboseName'   => 'file',
                             'verbosePlural' => 'files');

    static protected $_px = null;

    static protected $_adapter = null;

    protected $_dependentProxies = array(
        'images'    =>  'Media_Model_DbTable_Image',
        'videos'    =>  'Media_Model_DbTable_Video',
    );

    protected $_referenceMap = array(
        'user'   =>  array(
            'columns'       => 'user_id',
            'refColumns'    => 'id',
            'refTableClass' => 'Auth_Model_DbTable_User'
        )
    );

    protected $_dependentTables = array(
        'users'         =>  'Auth_Model_DbTable_User',
        'duplicates'    =>  'Media_Model_DbTable_Duplicate',
    );

    protected $_manyDependentTables = array(
        'tags'         => array(
            'refTableClass'     => 'Media_Model_DbTable_Tag',
            'intersectionTable' => 'Media_Model_DbTable_TagFile',
            'reflocal'          => 'file',
            'refforeign'        => 'tag',
            'columns'           => array(
                'local'     => 'file_id',
                'foreign'   => 'tag_id'
            )
        )
    );

    protected static $_mediaOptions = null;

    public static function setMediaOptions($options = array())
    {
        self::$_mediaOptions = $options;
    }

    public static function getPx()
    {
        if (null == self::$_px)
            self::$_px = Centurion_Db::getSingleton('media/file')->findOneByfile_id('88888888');
        return self::$_px;
    }

    /**
     * @return Media_Model_Adapter_Abstract
     */
    public static function getMediaAdapter()
    {
        if (null === self::$_adapter) {
            self::$_adapter = Media_Model_Adapter::factory(self::$_mediaOptions['adapter'],
                                                           self::$_mediaOptions['params']);
        }

        return self::$_adapter;
    }

    /**
     * @return array
     */
    public static function getMediaOptions()
    {
        return self::$_mediaOptions;
    }

    public function getDependentProxies()
    {
        return $this->_dependentProxies;
    }

    public function insert(array $data)
    {
        $primary = $this->_primary;
        if (is_array($primary)) {
            $primary = $primary[1];
        }

        if (!isset($data[$primary])) {
            $data[$primary] = md5(Centurion_Inflector::uniq(uniqid()));
        }

        if (!isset($data['sha1'])) {
            $data['sha1'] = sha1_file(Centurion_Config_Manager::get('media.uploads_dir')
                                      . DIRECTORY_SEPARATOR
                                      . $data['local_filename']);
        }

        $row = $this->fetchRow(array('sha1=?' => $data['sha1'], 'filesize=?' => $data['filesize']));
        //We want to be sure
        if ($row !== null && sha1_file(Centurion_Config_Manager::get('media.uploads_dir') . DIRECTORY_SEPARATOR . $data['local_filename']) == $row->sha1
                && filesize(Centurion_Config_Manager::get('media.uploads_dir') . DIRECTORY_SEPARATOR . $data['local_filename']) == $row->filesize) {

            //We reuse the same local filename
            unlink(Centurion_Config_Manager::get('media.uploads_dir') . DIRECTORY_SEPARATOR . $data['local_filename']);

            $data['file_id'] = $row->file_id;
            $data['local_filename'] = $row->local_filename;
            $data['filesize'] = $row->filesize;
            $data['proxy_model'] = $row->proxy_model;
            $data['proxy_pk'] = $row->proxy_pk;
            $data['belong_model'] = $row->belong_model;
            $data['belong_pk'] = $row->belong_pk;
        }

        if (!isset($data['file_id'])) {
            $data['file_id'] = $data[$primary];
        }

        if (!isset($data['proxy_pk'])) {
            foreach ($this->_dependentProxies as $key => $dependentProxy) {
                $proxyTable = Centurion_Db::getSingletonByClassName($dependentProxy);

                if (!in_array($data['mime'], array_keys($proxyTable->getMimeTypes()))) {
                    continue;
                }

                $cols = $proxyTable->info('cols');
                $proxyData = array();

                foreach ($data as $key => $value) {
                    if ($key == $primary || !in_array($key, $cols)) {
                        continue;
                    }

                   $proxyData[$key] = $value;
                   unset($data[$key]);
                }

                $proxyData = $data;
                unset($proxyData[$primary]);

                $pk = $proxyTable->insert($proxyData);

                $data = array_merge($data, array(
                    'proxy_model' =>  $dependentProxy,
                    'proxy_pk'    =>  $pk
                ));
            }
        }

        if (array_key_exists(self::BELONG_TO, $data)) {
            list($model, $pk) = $this->_setupProxyBelong($data[self::BELONG_TO]);

            $data = array_merge($data, array(
                'belong_model'  =>  $model,
                'belong_pk'     =>  $pk
            ));

            unset($data[self::BELONG_TO]);
        }

        return parent::insert($data);
    }

    protected function _setupProxyBelong($row)
    {
        return array(get_class($row->getTable()), $row->pk);
    }

    public function update(array $data, $where)
    {
        $currentFileRow = $this->fetchRow($where);

        if (null !== $currentFileRow->proxy_model) {
            $oldProxyTableClass = $currentFileRow->proxy_model;
        }

        foreach($currentFileRow->duplicates as $duplicate) {
            $duplicate->delete();
        }

        foreach ($this->_dependentProxies as $key => $dependentProxy) {
            $proxyTable = Centurion_Db::getSingletonByClassName($dependentProxy);
            $mimes = array_keys($proxyTable->getMimeTypes());

            if (!in_array($data['mime'], $mimes)) {
                continue;
            }

            if (in_array($data['mime'], $mimes)) {
                $newProxyTableClass = $dependentProxy;
                break;
            }
        }

        if (!isset($data['sha1'])) {
            $data['sha1'] = sha1_file(Centurion_Config_Manager::get('media.uploads_dir')
                                      . DIRECTORY_SEPARATOR
                                      . $data['local_filename']);
        }

        if (isset($oldProxyTableClass) && $oldProxyTableClass != $newProxyTableClass) {
            $currentProxyRow = Centurion_Db::getRow($oldProxyTableClass, $currentFileRow->proxy_pk);

            if (null !== $currentProxyRow) {
                $data = array_merge($data, array(
                    'proxy_model'  =>  null,
                    'proxy_pk'     =>  null
                ));

                $currentProxyRow->delete();
            }
        }

        if (null !== $newProxyTableClass) {
            $newProxyTable = Centurion_Db::getSingletonByClassName($newProxyTableClass);

            if (isset($oldProxyTableClass) && $oldProxyTableClass == $newProxyTableClass) {
                $pk = $newProxyTable->update($data, $newProxyTable->getAdapter()->quoteInto('id = ?', $currentFileRow->proxy_pk));
            } else {
                $pk = $newProxyTable->insert($data);

                $data['proxy_model'] = $newProxyTableClass;
            }

            $data['proxy_pk'] = $pk;
        }

        if (array_key_exists(self::BELONG_TO, $data)) {
            list($model, $pk) = $this->_setupProxyBelong($data[self::BELONG_TO]);

            $data = array_merge($data, array(
                'belong_model'  =>  $model,
                'belong_pk'     =>  $pk
            ));

            unset($data[self::BELONG_TO]);
        }

        return parent::update($data, $where);
    }

    public function getFilesFor($object, $select = null)
    {
        if (null === $select) {
            $select = $this->select(true);
        }

        return $select->belong($object)->fetchAll();
    }

    public function getFileFor($fileId, $object, $select = null)
    {
        if (null === $select) {
            $select = $this->select(true);
        }

        $select = $select->belong($object)
                         ->where('id = ?', $fileId);

        return $select->fetchRow();
    }
}
