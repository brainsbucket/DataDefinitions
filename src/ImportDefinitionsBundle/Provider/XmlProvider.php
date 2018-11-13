<?php
/**
 * Import Definitions.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2016-2018 w-vision AG (https://www.w-vision.ch)
 * @license    https://github.com/w-vision/ImportDefinitions/blob/master/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace ImportDefinitionsBundle\Provider;

use Pimcore\Model\Asset;
use ImportDefinitionsBundle\Model\ExportDefinitionInterface;
use ImportDefinitionsBundle\Model\ImportMapping\FromColumn;
use ImportDefinitionsBundle\ProcessManager\ArtifactGenerationProviderInterface;
use ImportDefinitionsBundle\ProcessManager\ArtifactProviderTrait;
use Symfony\Component\Inflector\Inflector;

class XmlProvider implements ProviderInterface, ExportProviderInterface, ArtifactGenerationProviderInterface
{
    use ArtifactProviderTrait;

    /** @var \XMLWriter */
    private $writer;

    /** @var string */
    private $exportPath;

    /** @var int */
    private $exportCounter = 0;

    /**
     * @param $xml
     * @param $xpath
     * @return mixed
     */
    protected function convertXmlToArray($xml, $xpath)
    {
        $xml = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $xml = $xml->xpath($xpath);

        $json = json_encode($xml);
        $array = json_decode($json, true);

        foreach ($array as &$arrayEntry) {
            if (array_key_exists('@attributes', $arrayEntry)) {
                foreach ($arrayEntry['@attributes'] as $key => $value) {
                    $arrayEntry['attr_' . $key] = $value;
                }
            }
        }

        return $array;
    }

    /**
     * {@inheritdoc}
     */
    public function testData($configuration)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumns($configuration)
    {
        $exampleFile = Asset::getById($configuration['exampleFile']);
        $rows = $this->convertXmlToArray($exampleFile->getData(), $configuration['exampleXPath']);
        $rows = $rows[0];

        $returnHeaders = [];

        if (\count($rows) > 0) {
            $firstRow = $rows;

            foreach ($firstRow as $key => $val) {
                $headerObj = new FromColumn();
                $headerObj->setIdentifier($key);
                $headerObj->setLabel($key);

                $returnHeaders[] = $headerObj;
            }
        }

        return $returnHeaders;
    }

    /**
     * {@inheritdoc}
     */
    public function getData($configuration, $definition, $params, $filter = null)
    {
        $file = sprintf('%s/%s', PIMCORE_PROJECT_ROOT, $params['file']);
        $xml = file_get_contents($file);

        return $this->convertXmlToArray($xml, $configuration['xPath']);
    }

    public function addExportData(array $data, $configuration, ExportDefinitionInterface $definition, $params)
    {
        $writer = $this->getXMLWriter();
        
        $this->serializeContainer($writer, 'object', $data);

        $this->exportCounter++;
        if ($this->exportCounter >= 50) {
            $this->flush($writer);
            $this->exportCounter = 0;
        }
    }

    public function exportData($configuration, ExportDefinitionInterface $definition, $params)
    {
        $writer = $this->getXMLWriter();

        // </root>
        $writer->endElement();
        $this->flush($writer);

        if (!array_key_exists('file', $params)) {
            return;
        }

        $file = sprintf('%s/%s', PIMCORE_PROJECT_ROOT, $params['file']);
        rename($this->getExportPath(), $file);
    }

    public function provideArtifactStream($configuration, ExportDefinitionInterface $definition, $params)
    {
        return fopen($this->getExportPath(), 'rb');
    }

    private function getXMLWriter(): \XMLWriter
    {
        if (null === $this->writer) {
            $this->writer = new \XMLWriter();
            $this->writer->openMemory();
            $this->writer->setIndent(true);
            $this->writer->startDocument('1.0', 'UTF-8');

            // <root>
            $this->writer->startElement('export');
        }

        return $this->writer;
    }

    private function getExportPath(): string
    {
        if (null === $this->exportPath) {
            $this->exportPath = tempnam(sys_get_temp_dir(), 'xml_export_provider');
        }

        return $this->exportPath;
    }

    private function flush(\XMLWriter $writer): void
    {
        file_put_contents($this->getExportPath(), $writer->flush(true), FILE_APPEND);
    }

    private function serialize(\XMLWriter $writer, string $name, $data, ?int $key = null): void
    {
        if (is_scalar($data)) {
            $writer->startElement('property');
            $writer->writeAttribute('name', $name);
            if (null !== $key) {
                $writer->writeAttribute('key', $key);
            }
            if (is_string($data)) {
                $writer->writeCdata($data);
            } else {
                $writer->text($data);
            }
            $writer->endElement();
        } else if (is_array($data)) {
            $this->serializeContainer($writer, $name, $data);
        } else {
            if ((string) $data) {
                $writer->startElement('property');
                $writer->writeAttribute('name', $name);
                if (null !== $key) {
                    $writer->writeAttribute('key', $key);
                }
                $writer->writeCdata((string) $data);
                $writer->endElement();
            }
        }
    }

    private function serializeContainer(\XMLWriter $writer, string $name, array $data): void
    {
        static $singularNames = [];

        $writer->startElement($name);
        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                if (!isset($singularNames[$name])) {
                    $name = Inflector::singularize($name);
                    if (is_array($name)) {
                        $name = end($name);
                    }
                    $singularNames[$name] = $name;
                }
                $this->serialize($writer, $singularNames[$name], $value, $key);
            } else {
                $this->serialize($writer, $key, $value);
            }
        }
        $writer->endElement();
    }
}
