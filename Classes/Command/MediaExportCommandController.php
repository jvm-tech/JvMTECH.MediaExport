<?php
namespace JvMTECH\MediaExport\Command;

use Doctrine\Common\Collections\Collection;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetSource\AssetSourceAwareInterface;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\Arrays;
use Neos\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */

class MediaExportCommandController extends CommandController
{

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * Export all Media Assets
     *
     * Export all Media Assets, optionally filtered by AssetSource and Tags
     *
     * @param string $assetSource If specified, only assets of this asset source are considered. For example "neos" or "my-asset-management-system"
     * @param string $onlyTags Comma-separated list of asset tags, that should be taken into account
     */
    public function allCommand(string $assetSource = '', string $onlyTags = '')
    {
        $this->removeAssets(false, $assetSource, $onlyTags);
    }

    /**
     * Export unused Media Assets
     *
     * Export unused Media Assets, optionally filtered by AssetSource and Tags
     *
     * @param string $assetSource If specified, only assets of this asset source are considered. For example "neos" or "my-asset-management-system"
     * @param string $onlyTags Comma-separated list of asset tags, that should be taken into account
     */
    public function unusedCommand(string $assetSource = '', string $onlyTags = '')
    {
        $this->removeAssets(true, $assetSource, $onlyTags);
    }

    /**
     * @param bool $onlyUnused
     * @param string $assetSource
     * @param string $onlyTags
     */
    private function removeAssets(bool $onlyUnused = true, string $assetSource = '', string $onlyTags = '')
    {
        $iterator = $this->assetRepository->findAllIterator();
        $assetCount = $this->assetRepository->countAll();
        $tableRowsByAssetSource = [];
        $exportedAssetCount = 0;
        $exportedAssetsTotalSize = 0;

        $filterByAssetSourceIdentifier = $assetSource;
        if ($filterByAssetSourceIdentifier === '') {
            $this->outputLine('<b>Searching for ' . ($onlyUnused ? 'unused ' : ' ') . 'assets in all asset sources:</b>');
        } else {
            $this->outputLine('<b>Searching for ' . ($onlyUnused ? 'unused ' : ' ') . 'assets of asset source "%s":</b>', [$filterByAssetSourceIdentifier]);
        }

        $assetTagsMatchFilterTags = function (Collection $assetTags, string $filterTags): bool {
            $filterTagValues = Arrays::trimExplode(',', $filterTags);
            $assetTagValues = [];
            foreach ($assetTags as $tag) {
                /** @var Tag $tag */
                $assetTagValues[] = $tag->getLabel();
            }
            return count(array_intersect($filterTagValues, $assetTagValues)) > 0;
        };

        $this->output->progressStart($assetCount);

        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            $this->output->progressAdvance(1);

            if (!$asset instanceof Asset) {
                continue;
            }
            if (!$asset instanceof AssetSourceAwareInterface) {
                continue;
            }
            if ($filterByAssetSourceIdentifier !== '' && $asset->getAssetSourceIdentifier() !== $filterByAssetSourceIdentifier) {
                continue;
            }
            if ($onlyTags !== '' && $assetTagsMatchFilterTags($asset->getTags(), $onlyTags) === false) {
                continue;
            }
            if ($onlyUnused && $asset->getUsageCount() !== 0) {
                continue;
            }


            if (!file_exists(FLOW_PATH_DATA . 'MediaExport')) {
                mkdir(FLOW_PATH_DATA . 'MediaExport');
            }

            $resource = $asset->getResource()->getStream();

            $tagLabels = array_map(function($tag){
                return $tag->getLabel();
            }, $asset->getTags()->toArray());

            $assetCollectionTitles = array_map(function($assetCollection){
                return $assetCollection->getTitle();
            }, $asset->getAssetCollections()->toArray());

            $meta = [
                'identifier' => $asset->getIdentifier(),
                'title' => $asset->getTitle(),
                'caption' => $asset->getCaption(),
                'lastModified' => $asset->getLastModified()->getTimestamp(),
                'tags' => $tagLabels,
                'assetCollections' => $assetCollectionTitles,
            ];

            file_put_contents(FLOW_PATH_DATA . 'MediaExport/' . $asset->getResource()->getFilename(), stream_get_contents($resource));
            file_put_contents(FLOW_PATH_DATA . 'MediaExport/' . $asset->getResource()->getFilename() . '.meta', json_encode($meta));

            $fileSize = str_pad(Files::bytesToSizeString($asset->getResource()->getFileSize()), 9, ' ', STR_PAD_LEFT);

            $tableRowsByAssetSource[$asset->getAssetSourceIdentifier()][] = [
                $asset->getIdentifier(),
                $asset->getResource()->getFilename(),
                $fileSize
            ];
            $exportedAssetCount++;
            $exportedAssetsTotalSize += $asset->getResource()->getFileSize();
        }

        $this->output->progressFinish();

        if ($exportedAssetCount === 0) {
            $this->output->outputLine(PHP_EOL . 'No ' . ($onlyUnused ? 'unused ' : ' ') . 'assets found.');
            exit;
        }

        foreach ($tableRowsByAssetSource as $assetSourceIdentifier => $tableRows) {
            $this->outputLine(PHP_EOL . 'Exported the following ' . ($onlyUnused ? 'unused ' : ' ') . 'assets from asset source <success>%s</success>: ' . PHP_EOL, [$assetSourceIdentifier]);

            $this->output->outputTable(
                $tableRows,
                ['Asset identifier', 'Filename', 'Size']
            );
        }

        $this->outputLine(PHP_EOL . 'Total size of %s exported assets: %s' . PHP_EOL, [$exportedAssetCount, Files::bytesToSizeString($exportedAssetsTotalSize)]);
    }
}
