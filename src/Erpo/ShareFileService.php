<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Erpo;

use App\Entity\Archiver;
use App\Service\ShareFileClient;
use App\ShareFile\Item;
use Kapersoft\ShareFile\Client;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class ShareFileService
{
    private const SHAREFILE_FOLDER = 'ShareFile.Api.Models.Folder';
    private const SHAREFILE_FILE = 'ShareFile.Api.Models.File';

    /** @var Archiver */
    private $archiver;

    /** @var array */
    private $configuration;

    /** @var Client */
    private $client;

    public function setArchiver(Archiver $archiver)
    {
        $this->archiver = $archiver;
        $this->configuration = $archiver->getConfigurationValue('sharefile', []);
        $this->validateConfiguration();
    }

    /**
     * Check that we can connect to ShareFile.
     */
    public function connect()
    {
        $this->client()->getItemById($this->configuration['root_id']);
    }

    /**
     * @param null|\DateTime $changedAfter
     *
     * @return Item[]
     */
    public function getUpdatedFiles(\DateTime $changedAfter)
    {
        $items = $this->getErpoItems($changedAfter);
        foreach ($items as &$item) {
            $files = $this->getFiles($item, $changedAfter);
            $item->setChildren($files);
        }

        return $items;
    }

    /**
     * @return Item[]
     */
    public function getErpoItems(\DateTime $changedAfter = null)
    {
        $itemId = $this->configuration['root_id'];
        $folders = $this->getFolders($itemId, $changedAfter);
        $erpoItems = array_filter($folders ?? [], function ($item) use ($changedAfter) {
            if ($changedAfter && isset($item['ProgenyEditDate'])
                && new \DateTime($item['ProgenyEditDate']) < $changedAfter) {
                return false;
            }

            return $this->isErpoItem($item);
        });

        return $this->construct(Item::class, $erpoItems);
    }

    public function getErpoItem($itemId)
    {
        $item = $this->getItem($itemId);
        $files = $this->getFiles($item);
        $item->setChildren($files);

        return $item;
    }

    /**
     * @return Item[]
     */
    public function getResponses(Item $hearing, \DateTime $changedAfter = null)
    {
        $folders = $this->getFolders($hearing, $changedAfter);
        $responses = array_filter($folders ?? [], function ($item) use ($changedAfter) {
            if ($changedAfter && isset($item['ProgenyEditDate'])
                    && new \DateTime($item['ProgenyEditDate']) < $changedAfter) {
                return false;
            }

            return $this->isHearingResponse($item);
        });

        return $this->construct(Item::class, $responses);
    }

    /**
     * @param $item
     *
     * @return Item
     */
    public function getItem($item)
    {
        $itemId = $this->getItemId($item);
        $item = $this->client()->getItemById($itemId);

        $this->setMetadata($item);

        return new Item($item);
    }

    /**
     * Get metadata list.
     *
     * @param $item
     *
     * @return array
     */
    public function getMetadata($item, array $names = null)
    {
        $itemId = $this->getItemId($item);
        $metadata = $this->client()->getItemMetadataList($itemId);
        if (null !== $names) {
            $metadata['value'] = array_filter($metadata['value'], function ($item) use ($names) {
                return isset($item['Name']) && \in_array($item['Name'], $names, true);
            });
        }

        $result = [];
        foreach ($metadata['value'] as $metadatum) {
            $result[$metadatum['Name']] = $metadatum;
        }

        return $result;
    }

    /**
     * Get all metadata values.
     *
     * @param $item
     *
     * @return array
     */
    public function getMetadataValues($item, array $names = null)
    {
        $metadata = $this->getMetadata($item, $names);

        return array_map(function ($metadatum) {
            $value = $metadatum['Value'];

            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Exception $e) {
                return $value;
            }
        }, $metadata);
    }

    /**
     * Get a single metadata value.
     *
     * @param $item
     *
     * @return null|mixed
     */
    public function getMetadataValue($item, string $name)
    {
        $metadata = $this->getMetadataValues($item, [$name]);

        return $metadata[$name] ?? null;
    }

    public function getFiles($item, \DateTime $changedAfter = null)
    {
        $itemId = $this->getItemId($item);
        $children = $this->getChildren($itemId, self::SHAREFILE_FILE, $changedAfter);
        $files = array_filter($children ?? [], function ($item) use ($changedAfter) {
            return !(null !== $changedAfter && isset($item['CreationDate'])
                && new \DateTime($item['CreationDate']) < $changedAfter);
        });
        // Add metadata values to each file.
        foreach ($files as &$file) {
            $this->setMetadata($file);
        }

        return $this->construct(Item::class, $files);
    }

    public function getFolders($item, \DateTime $changedAfter = null)
    {
        $itemId = $this->getItemId($item);

        $folders = $this->getChildren($itemId, self::SHAREFILE_FOLDER, $changedAfter);

        // Add metadata values to each folder.
        foreach ($folders as &$folder) {
            $this->setMetadata($folder);
        }

        return $folders;
    }

    public function downloadFile($item)
    {
        $itemId = $this->getItemId($item);

        return $this->client()->getItemContents($itemId);
    }

    public function uploadFile(string $filename, string $folderId, bool $unzip = false, bool $overwrite = true, bool $notify = true)
    {
        $result = $this->client()->uploadFileStandard($filename, $folderId, $unzip, $overwrite, $notify);

        return $result;
    }

    public function findFile(string $filename, string $folderId)
    {
        $result = $this->client()->getChildren(
            $folderId,
            [
                '$filter' => 'Name eq \''.str_replace('\'', '\\\'', $filename).'\'',
            ]
        );

        if (!isset($result['value']) || 1 !== \count($result['value'])) {
            throw new \RuntimeException(sprintf('No such file %s in folder %s', $filename, $folderId));
        }

        return new Item(reset($result['value']));
    }

    /**
     * @param Item[] $hearings
     */
    public function dump(array $hearings, OutputInterface $output)
    {
        $table = new Table($output);

        foreach ($hearings as $hearing) {
            $table->addRow([
                $hearing->name,
                $hearing->id,
                $hearing->progenyEditDate,
            ]);
            foreach ($hearing->getChildren() as $reply) {
                $table->addRow([
                    ' '.$reply->name,
                    $reply->id,
                    $reply->progenyEditDate,
                    json_encode($this->getMetadata($reply), JSON_PRETTY_PRINT),
                ]);
                foreach ($reply->getChildren() as $file) {
                    $table->addRow([
                        '  '.$file->name,
                        $file->id,
                    ]);
                }
            }
        }

        $table->render();
    }

    private function setMetadata(array &$item)
    {
        $item['_metadata'] = $this->getMetadataValues($item['Id']);
    }

    private function validateConfiguration()
    {
        $requiredFields = ['hostname', 'client_id', 'secret', 'username', 'password', 'root_id'];
        foreach ($requiredFields as $field) {
            if (!isset($this->configuration[$field])) {
                throw new \RuntimeException('Configuration value "'.$field.'" missing.');
            }
        }
    }

    private function getItemId($item)
    {
        return $item instanceof Item ? $item->id : $item;
    }

    private function getChildren(string $itemId, string $type, \DateTime $changedAfter = null)
    {
        $query = [
            //            '$select' => implode(',', [
            //                'Id',
            //                'CreationDate',
            //                'Name',
            //// https://community.sharefilesupport.com/citrixsharefile/topics/using-api-what-way-can-clients-listen-for-new-files?topic-reply-list[settings][filter_by]=all&topic-reply-list[settings][reply_id]=17731261#reply_17731261
            //                'ProgenyEditDate',
            //            ]),

            //            '$orderby' => 'ProgenyEditDate asc',

            //            '$expand' => implode(',', [
            //                'Children',
            //                'Children/Children',
            //            ]),
            '$filter' => 'isof(\''.$type.'\')',
        ];

        // Filter on "ProgenyEditDate" results in "500 Internal server error" in ShareFile API if non-folder items (i.e. items with no ProgenyEditDate property) exists in parent.
//        if (null !== $changedAfter && self::SHAREFILE_FOLDER === $type) {
//            if (isset($query['$filter'])) {
//                $query['$filter'] .= ' and ';
//            } else {
//                $query['$filter'] = '';
//            }
//            // https://www.odata.org/documentation/odata-version-3-0/odata-version-3-0-core-protocol/#thefiltersystemqueryoption
//            $query['$filter'] .= 'ProgenyEditDate gt date('.$changedAfter->format('Y-m-d').')';
//        }

        return $this->getAllChildren($itemId, $query);
    }

    /**
     * Get all children by following "nextlink" in result.
     *
     * @return array
     */
    private function getAllChildren(string $itemId, array $query)
    {
        $result = $this->client()->getChildren($itemId, $query);

        if (!isset($result['value'])) {
            return [];
        }

        $values[] = $result['value'];

        // "odata.nextLink" seems to be incorrect when usign both $skip and $top.
//        while (isset($result['odata.nextLink'])) {
//            $url = parse_url($result['odata.nextLink']);
//            parse_str($url['query'], $query);
//            $result = $this->client()->getChildren($itemId, $query);
//            if (isset($result['value'])) {
//                $values[] = $result['value'];
//            }
//        }

        $pageSize = \count($result['value']);
        if ($pageSize > 0) {
            $numberOfPages = (int) ceil($result['odata.count'] / $pageSize);
            for ($page = 2; $page <= $numberOfPages; ++$page) {
                $query['$skip'] = $pageSize * ($page - 1);
                $result = $this->client()->getChildren($itemId, $query);
                if (isset($result['value'])) {
                    $values[] = $result['value'];
                }
            }
        }

        // Flatten the results.
        return array_merge(...$values);
    }

//    private function getChildren($itemId) {
//        $result = $this->client()->getItemById($itemId, true);
//
//        return $result['Children'] ?? null;
//    }

    /**
     * @throws \Exception
     *
     * @return Client
     */
    private function client()
    {
        if (null === $this->client) {
            $this->client = new ShareFileClient(
                $this->configuration['hostname'],
                $this->configuration['client_id'],
                $this->configuration['secret'],
                $this->configuration['username'],
                $this->configuration['password']
            );
        }

        return $this->client;
    }

    private function isErpoItem(array $item)
    {
        return true;
    }

    private function isHearingResponse(array $item)
    {
        return preg_match('/^HS[0-9]+$/', $item['Name']);
    }

    private function construct($class, array $items)
    {
        return array_map(function (array $data) use ($class) {
            return new $class($data);
        }, $items);
    }
}
