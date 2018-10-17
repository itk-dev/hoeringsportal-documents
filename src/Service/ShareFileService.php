<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Service;

use Kapersoft\ShareFile\Client;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ShareFileService
{
    const SHAREFILE_FOLDER = 'ShareFile.Api.Models.Folder';
    const SHAREFILE_FILE = 'ShareFile.Api.Models.File';
    /** @var array */
    private $configuration;

    /** @var Client */
    private $client;

    public function __construct(ParameterBagInterface $parameters)
    {
        $this->configuration = array_filter($parameters->all(), function ($key) {
            return preg_match('/^sharefile_/', $key);
        }, ARRAY_FILTER_USE_KEY);
    }

    public function getUpdatedFiles(\DateTime $changedAfter)
    {
        $hearings = $this->getHearings($changedAfter);
        foreach ($hearings as &$hearing) {
            $responses = $this->getResponses($hearing, $changedAfter);
            foreach ($responses as &$response) {
                $files = $this->getFiles($response, $changedAfter);
                $response['_files'] = $files;
            }
            $hearing['_responses'] = $responses;
        }

        return $hearings;
    }

    public function getHearings(\DateTime $changedAfter = null)
    {
        $itemId = $this->configuration['sharefile_root_id'];
        $hearings = $this->getFolders($itemId, $changedAfter);

        return array_filter($hearings ?? [], function ($item) use ($changedAfter) {
            if ($changedAfter && isset($item['ProgenyEditDate'])
                    && new \DateTime($item['ProgenyEditDate']) < $changedAfter) {
                return false;
            }

            return $this->isHearing($item);
        });
    }

    public function getResponses(array $hearing, \DateTime $changedAfter = null)
    {
        $responses = $this->getFolders($hearing, $changedAfter);

        return array_filter($responses ?? [], function ($item) use ($changedAfter) {
            if ($changedAfter && isset($item['ProgenyEditDate'])
                    && new \DateTime($item['ProgenyEditDate']) < $changedAfter) {
                return false;
            }

            return $this->isHearingResponse($item);
        });
    }

    public function getItem($item)
    {
        $itemId = $this->getItemId($item);

        return $this->client()->getItemById($itemId);
    }

    public function getFiles($item, \DateTime $changedAfter = null)
    {
        $itemId = $this->getItemId($item);
        $files = $this->getChildren($itemId, self::SHAREFILE_FILE, $changedAfter);

        return array_filter($files ?? [], function ($item) use ($changedAfter) {
            if ($changedAfter && isset($item['CreationDate'])
                && new \DateTime($item['CreationDate']) < $changedAfter) {
                return false;
            }

            return true;
        });
    }

    public function getFolders($item, \DateTime $changedAfter = null)
    {
        $itemId = $this->getItemId($item);

        return $this->getChildren($itemId, self::SHAREFILE_FOLDER, $changedAfter);
    }

    public function downloadFile(array $file)
    {
        $itemId = $this->getItemId($file);

        return $this->client()->getItemContents($itemId);
    }

    private function getItemId($item)
    {
        return \is_array($item) ? $item['Id'] : $item;
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

        $result = $this->getAllChildren($itemId, $query);

        return $result;
    }

    /**
     * Get all children by following "nextlink" in result.
     *
     * @param string $itemId
     * @param array  $query
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

    private function client()
    {
        if (null === $this->client) {
            $this->client = new Client(
                $this->configuration['sharefile_hostname'],
                $this->configuration['sharefile_client_id'],
                $this->configuration['sharefile_secret'],
                $this->configuration['sharefile_username'],
                $this->configuration['sharefile_password']
            );
        }

        return $this->client;
    }

    private function isHearing(array $item)
    {
        return preg_match('/^H[0-9]+$/', $item['Name']);
    }

    private function isHearingResponse(array $item)
    {
        return preg_match('/^HS[0-9]+$/', $item['Name']);
    }
}
