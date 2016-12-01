<?php
namespace SICOR\SicAddress\Controller;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 SICOR DEVTEAM <dev@sicor-kdl.net>, Sicor KDL GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use Metaseo\Metaseo\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * ExportController
 */
class ExportController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController {

    /**
     * addressRepository
     *
     * @var \SICOR\SicAddress\Domain\Repository\AddressRepository
     * @inject
     */
    protected $addressRepository = NULL;

    /**
     * categoryRepository
     *
     * @var \SICOR\SicAddress\Domain\Repository\CategoryRepository
     * @inject
     */
    protected $categoryRepository = NULL;

    /**
     * domainPropertyRepository
     *
     * @var \SICOR\SicAddress\Domain\Repository\DomainPropertyRepository
     * @inject
     */
    protected $domainPropertyRepository = NULL;

    /**
     * Holds the Typoscript configuration
     *
     * @var \TYPO3\CMS\Extbase\Configuration
     */
    protected $extbaseFrameworkConfiguration = NULL;

    /**
     * Template Root Path
     *
     * @var string
     */
    protected $templateRootPath = "";

    /**
     * Called before any action
     */
    public function initializeAction() {
        $this->setBackendModuleTemplates();

        $this->extbaseFrameworkConfiguration = $this->configurationManager->getConfiguration(\TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);
        $this->templateRootPath = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->extbaseFrameworkConfiguration['view']['exportTemplateRootPaths'][0]);
    }

    /**
     * Set Backend Module Templates
     * @return void
     */
    private function setBackendModuleTemplates(){
        $frameworkConfiguration = $this->configurationManager->getConfiguration(\TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);
        $typoscriptConfiguration = $this->configurationManager->getConfiguration(\TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);

        $templates = $GLOBALS['TYPO3_DB']->exec_SELECTquery('config,constants', "sys_template", 'deleted = 0 AND hidden = 0', '');
        while ($template = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($templates)) {
            if(strpos($template['config'], "module.tx_sicaddress_web_sicaddresssicaddressexport") >= 0) {
                $TSparserObject = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser::class);
                $TSparserObject->parse($template['config']);

                $typoscriptConfiguration = array_merge_recursive($typoscriptConfiguration, $TSparserObject->setup);
            }
        }
        $frameworkConfiguration["view"]["exportTemplateRootPaths"]["0"] = $typoscriptConfiguration["module."]["tx_sicaddress_web_sicaddresssicaddressexport."]["view."]["exportTemplateRootPaths."][1];
        $this->configurationManager->setConfiguration($frameworkConfiguration);
    }


    /**
     * exportAction
     *
     * Display export page
     */
    public function exportAction() {
        $pid = \TYPO3\CMS\Core\Utility\GeneralUtility::_GP('id');
        $addresses = $this->addressRepository->findByPid($pid);
        $categories = $this->getCurrentCategories($addresses);

        $this->view->assign("categories", $categories);
        $this->view->assign("templates", $this->getTemplates());
        $this->view->assign("sorting", $this->domainPropertyRepository->findByType("string"));
    }

    /**
     * exportToFileAction
     *
     * @return void
     */
    public function exportToFileAction() {
        $arguments = $this->request->getArguments();
        $categories = $arguments["categories"];
        $type = $arguments["type"];
        $template = $arguments["template"];
        $sorting = $arguments["sorting"];

        $this->addressRepository->setDefaultOrderings(array($sorting => \TYPO3\CMS\Extbase\Persistence\QueryInterface::ORDER_ASCENDING));
        $addresses = $this->addressRepository->findByCategories($categories);

        if ($type == "CSV") {
            $this->exportToCSV($addresses, "csv.tmpl");
        } else {
            $this->exportToHTML($addresses, $template);
        }
    }

    /**
     * exportToCSVAction
     *
     * @param $addresses
     * @param $template
     */
    private function exportToCSV($addresses, $template) {
        $customView = $this->objectManager->get('TYPO3\\CMS\\Fluid\\View\\StandaloneView');

        $templatePathAndFilename = $this->templateRootPath . $template;
        $customView->setTemplatePathAndFilename($templatePathAndFilename);
        $customView->setPartialRootPath($templatePathAndFilename);
        $customView->assign("addresses", $this->parseCSV($addresses));
        $content = $customView->render();

        header("Content-type: text/csv");
        header("Content-Disposition: attachment; filename=Export.csv");
        echo $content;
        exit;
    }


    /**
     * exportToHTMLAction
     *
     * @param $addresses
     * @param $template
     */
    private function exportToHTML($addresses, $template) {
        $customView = $this->objectManager->get('TYPO3\\CMS\\Fluid\\View\\StandaloneView');

        $templatePathAndFilename = $this->templateRootPath . $template;
        $customView->setTemplatePathAndFilename($templatePathAndFilename);
        $customView->setPartialRootPath($templatePathAndFilename);
        $customView->assign("addresses", $addresses);
        $content = $customView->render();

        header("Content-type: text/html");
        header("Content-Disposition: attachment; filename=Export.html");
        echo $content;
        exit;
    }

    /**
     * @param array $addresses
     *
     * @return array
    */
    private function getCurrentCategories($addresses) {
        $categories = array();
        foreach($addresses as $address) {
            $category = $address->getCategories();
            foreach ($category as $entry) {
                if (!in_array($entry, $categories) && $entry->getParent()) {
                    $categories[$entry->getParent()->getTitle()]["value"] = $entry->getParent();
                    $categories[$entry->getParent()->getTitle()]["entries"][$entry->getTitle()] = $entry;
                }
            }
        }

        return $categories;
    }

    /**
     * @return array
     */
    private function getTemplates() {
        $templates = array();
        $entries = array_diff(scandir($this->templateRootPath), array('.', '..'));
        foreach ($entries as $entry) {
            if(!strpos($entry, ".tmpl")) {
                $template = new \stdClass();
                $template->key = $entry;
                $template->value = $entry;
                $templates[] = $template;
            }
        }
        return $templates;
    }

    /**
     * @param array $values
     * @return string
     */
    public function parseCSV($values) {
        $domainProperties = $this->domainPropertyRepository->findAll();
        foreach($values->toArray() as $key => $domainObject) {
            foreach($domainProperties as $property) {
                $propertyTitle = \TYPO3\CMS\Core\Utility\GeneralUtility::underscoredToLowerCamelCase($property->getTitle());

                $value = \TYPO3\CMS\Extbase\Reflection\ObjectAccess::getProperty($domainObject, $propertyTitle);
                if($value instanceof \TYPO3\CMS\Extbase\Persistence\ObjectStorage)
                    continue;

                $parseValue = "\"" . str_replace("\"", "\"\"", $value) . "\"";
                \TYPO3\CMS\Extbase\Reflection\ObjectAccess::setProperty($domainObject, $propertyTitle, $parseValue);
            }
        }
        return $values;
    }
}
