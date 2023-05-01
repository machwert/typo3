<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Pixxio\PixxioExtension\Controller;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Form\Event\CustomFileControlsEvent;
use TYPO3\CMS\Backend\Form\InlineStackProcessor;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3Fluid\Fluid\View\TemplateView;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Backend\Form\Container\FileReferenceContainer;

/**
 * Files entry container.
 *
 * This container is the entry step to rendering a file reference. It is created by SingleFieldContainer.
 *
 * The code creates the main structure for the single file reference, initializes the inlineData array,
 * that is manipulated and also returned back in its manipulated state. The "control" stuff of file
 * references is rendered here, for example the "create new" button.
 *
 * For each existing file reference, a FileReferenceContainer is called for further processing.
 */
class FilesControlContainer extends \TYPO3\CMS\Backend\Form\Container\FilesControlContainer
{
    private $applikationKey = 'ghx8F66X3ix4AJ0VmS0DE8sx7';
    
    public const NODE_TYPE_IDENTIFIER = 'file';

    private const FILE_REFERENCE_TABLE = 'sys_file_reference';

    /**
     * Inline data array used in JS, returned as JSON object to frontend
     */
    protected array $fileReferenceData = [];

    /**
     * @var array<int,JavaScriptModuleInstruction|string|array<string,string>>
     */
    protected array $javaScriptModules = [];

    protected IconFactory $iconFactory;
    protected InlineStackProcessor $inlineStackProcessor;

    protected $defaultFieldInformation = [
        'tcaDescription' => [
            'renderType' => 'tcaDescription',
        ],
    ];

    protected $defaultFieldWizard = [
        'localizationStateSelector' => [
            'renderType' => 'localizationStateSelector',
        ],
    ];

    private AssetCollector $assetCollector;

    /**
     * Container objects give $nodeFactory down to other containers.
     */
    public function __construct(NodeFactory $nodeFactory, array $data)
    {
        parent::__construct($nodeFactory, $data);
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
    }

    /**
     * Entry method
     *
     * @return array As defined in initializeResultArray() of AbstractNode
     */
    public function render()
    {
        $languageService = $this->getLanguageService();

        $this->fileReferenceData = $this->data['inlineData'];

        $inlineStackProcessor = GeneralUtility::makeInstance(InlineStackProcessor::class);
        $this->inlineStackProcessor = $inlineStackProcessor;
        $inlineStackProcessor->initializeByGivenStructure($this->data['inlineStructure']);

        $table = $this->data['tableName'];
        $row = $this->data['databaseRow'];
        $field = $this->data['fieldName'];
        $parameterArray = $this->data['parameterArray'];

        $resultArray = $this->initializeResultArray();

        $config = $parameterArray['fieldConf']['config'];
        $isReadOnly = (bool)($config['readOnly'] ?? false);
        $language = 0;
        if (BackendUtility::isTableLocalizable($table)) {
            $languageFieldName = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '';
            $language = isset($row[$languageFieldName][0]) ? (int)$row[$languageFieldName][0] : (int)$row[$languageFieldName];
        }

        // Add the current inline job to the structure stack
        $newStructureItem = [
            'table' => $table,
            'uid' => $row['uid'],
            'field' => $field,
            'config' => $config,
        ];

        // Extract FlexForm parts (if any) from element name, e.g. array('vDEF', 'lDEF', 'FlexField', 'vDEF')
        $itemName = (string)$parameterArray['itemFormElName'];
        if ($itemName !== '') {
            $flexFormParts = $this->extractFlexFormParts($itemName);
            if ($flexFormParts !== null) {
                $newStructureItem['flexform'] = $flexFormParts;
                if ($flexFormParts !== []
                    && isset($this->data['processedTca']['columns'][$field]['config']['dataStructureIdentifier'])
                ) {
                    // Transport the flexform DS identifier fields to the FormFilesAjaxController
                    $config['dataStructureIdentifier'] = $this->data['processedTca']['columns'][$field]['config']['dataStructureIdentifier'];
                }
            }
        }

        $inlineStackProcessor->pushStableStructureItem($newStructureItem);

        // Hand over original returnUrl to FormFilesAjaxController. Needed if opening for instance a
        // nested element in a new view to then go back to the original returnUrl and not the url of
        // the inline ajax controller
        $config['originalReturnUrl'] = $this->data['returnUrl'];

        // e.g. data[<table>][<uid>][<field>]
        $formFieldName = $inlineStackProcessor->getCurrentStructureFormPrefix();
        // e.g. data-<pid>-<table1>-<uid1>-<field1>-<table2>-<uid2>-<field2>
        $formFieldIdentifier = $inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($this->data['inlineFirstPid']);

        $config['inline']['first'] = false;

        $firstChild = reset($this->data['parameterArray']['fieldConf']['children']);
        if (isset($firstChild['databaseRow']['uid'])) {
            $config['inline']['first'] = $firstChild['databaseRow']['uid'];
        }
        $config['inline']['last'] = false;
        $lastChild = end($this->data['parameterArray']['fieldConf']['children']);
        if (isset($lastChild['databaseRow']['uid'])) {
            $config['inline']['last'] = $lastChild['databaseRow']['uid'];
        }

        $top = $inlineStackProcessor->getStructureLevel(0);

        $this->fileReferenceData['config'][$formFieldIdentifier] = [
            'table' => self::FILE_REFERENCE_TABLE,
        ];
        $configJson = (string)json_encode($config);
        $this->fileReferenceData['config'][$formFieldIdentifier . '-' . self::FILE_REFERENCE_TABLE] = [
            'min' => $config['minitems'],
            'max' => $config['maxitems'],
            'sortable' => $config['appearance']['useSortable'] ?? false,
            'top' => [
                'table' => $top['table'],
                'uid' => $top['uid'],
            ],
            'context' => [
                'config' => $configJson,
                'hmac' => GeneralUtility::hmac($configJson, 'FilesContext'),
            ],
        ];
        $this->fileReferenceData['nested'][$formFieldIdentifier] = $this->data['tabAndInlineStack'];

        $resultArray['inlineData'] = $this->fileReferenceData;

        // @todo: It might be a good idea to have something like "isLocalizedRecord" or similar set by a data provider
        $uidOfDefaultRecord = $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? null] ?? 0;
        $isLocalizedParent = $language > 0
            && ($uidOfDefaultRecord[0] ?? $uidOfDefaultRecord) > 0
            && MathUtility::canBeInterpretedAsInteger($row['uid']);
        $numberOfFullLocalizedChildren = 0;
        $numberOfNotYetLocalizedChildren = 0;
        foreach ($this->data['parameterArray']['fieldConf']['children'] as $child) {
            if (!$child['isInlineDefaultLanguageRecordInLocalizedParentContext']) {
                $numberOfFullLocalizedChildren++;
            }
            if ($isLocalizedParent && $child['isInlineDefaultLanguageRecordInLocalizedParentContext']) {
                $numberOfNotYetLocalizedChildren++;
            }
        }

        if ($isReadOnly || $numberOfFullLocalizedChildren >= ($config['maxitems'] ?? 0)) {
            $config['inline']['showNewFileReferenceButton'] = false;
            $config['inline']['showCreateNewRelationButton'] = false;
            $config['inline']['showOnlineMediaAddButtonStyle'] = false;
        }

        $fieldInformationResult = $this->renderFieldInformation();
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldInformationResult, false);

        $fieldWizardResult = $this->renderFieldWizard();
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldWizardResult, false);

        $sortableRecordUids = $fileReferencesHtml = [];
        foreach ($this->data['parameterArray']['fieldConf']['children'] as $options) {
            $options['inlineParentUid'] = $row['uid'];
            $options['inlineFirstPid'] = $this->data['inlineFirstPid'];
            $options['inlineParentConfig'] = $config;
            $options['inlineData'] = $this->fileReferenceData;
            $options['inlineStructure'] = $inlineStackProcessor->getStructure();
            $options['inlineExpandCollapseStateArray'] = $this->data['inlineExpandCollapseStateArray'];
            $options['renderType'] = FileReferenceContainer::NODE_TYPE_IDENTIFIER;
            $fileReference = $this->nodeFactory->create($options)->render();
            $fileReferencesHtml[] = $fileReference['html'];
            $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fileReference, false);
            if (!$options['isInlineDefaultLanguageRecordInLocalizedParentContext'] && isset($options['databaseRow']['uid'])) {
                // Don't add record to list of "valid" uids if it is only the default
                // language record of a not yet localized child
                $sortableRecordUids[] = $options['databaseRow']['uid'];
            }
        }

        // @todo: It's unfortunate we're using Typo3Fluid TemplateView directly here. We can't
        //        inject BackendViewFactory here since __construct() is polluted by NodeInterface.
        //        Remove __construct() from NodeInterface to have DI, then use BackendViewFactory here.
        $view = GeneralUtility::makeInstance(TemplateView::class);
        $templatePaths = $view->getRenderingContext()->getTemplatePaths();
        $templatePaths->setTemplateRootPaths([GeneralUtility::getFileAbsFileName('EXT:backend/Resources/Private/Templates')]);
        $view->assignMultiple([
            'formFieldIdentifier' => $formFieldIdentifier,
            'formFieldName' => $formFieldName,
            'formGroupAttributes' => GeneralUtility::implodeAttributes([
                'class' => 'form-group',
                'id' => $formFieldIdentifier,
                'data-uid' => (string)$row['uid'],
                'data-local-table' => (string)$top['table'],
                'data-local-field' => (string)$top['field'],
                'data-foreign-table' => self::FILE_REFERENCE_TABLE,
                'data-object-group' => $formFieldIdentifier . '-' . self::FILE_REFERENCE_TABLE,
                'data-form-field' => $formFieldName,
                'data-appearance' => (string)json_encode($config['appearance'] ?? ''),
            ], true),
            'fieldInformation' => $fieldInformationResult['html'],
            'fieldWizard' => $fieldWizardResult['html'],
            'fileReferences' => [
                'id' => $formFieldIdentifier . '_records',
                'title' => $languageService->sL(trim($parameterArray['fieldConf']['label'] ?? '')),
                'records' => implode(LF, $fileReferencesHtml),
            ],
            'sortableRecordUids' => implode(',', $sortableRecordUids),
            'validationRules' => $this->getValidationDataAsJsonString([
                'type' => 'inline',
                'minitems' => $config['minitems'] ?? null,
                'maxitems' => $config['maxitems'] ?? null,
            ]),
        ]);

        if (!$isReadOnly && ($config['appearance']['showFileSelectors'] ?? true) !== false) {
            $allowedFileTypes = GeneralUtility::trimExplode(',', (string)($config['allowed'] ?? ''), true);
            $view->assign('fileSelectors', $this->getFileSelectors($config, $allowedFileTypes));
            $view->assign('allowedFileTypes', $allowedFileTypes);
            // Render the localization buttons if needed
            if ($numberOfNotYetLocalizedChildren) {
                $view->assignMultiple([
                    'showAllLocalizationLink' => !empty($config['appearance']['showAllLocalizationLink']),
                    'showSynchronizationLink' => !empty($config['appearance']['showSynchronizationLink']),
                ]);
            }
        }

        $controls = GeneralUtility::makeInstance(EventDispatcherInterface::class)->dispatch(
            new CustomFileControlsEvent($resultArray, $table, $field, $row, $config, $formFieldIdentifier, $formFieldName)
        )->getControls();

        if ($controls !== []) {
            $view->assign('customControls', [
                'id' => $formFieldIdentifier . '_customControls',
                'controls' => implode("\n", $controls),
            ]);
        }

        $resultArray['html'] = $view->render('Form/FilesControlContainer');
        $resultArray['javaScriptModules'] = array_merge($resultArray['javaScriptModules'], $this->javaScriptModules);
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@typo3/backend/form-engine/container/files-control-container.js');

        return $resultArray;
    }

    /**
     * Generate buttons to select, reference and upload files.
     */
    protected function getFileSelectors(array $inlineConfiguration, array $allowedFileTypes): array
    {
        $languageService = $this->getLanguageService();
        $backendUser = $this->getBackendUserAuthentication();

        $currentStructureDomObjectIdPrefix = $this->inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($this->data['inlineFirstPid']);
        $objectPrefix = $currentStructureDomObjectIdPrefix . '-' . self::FILE_REFERENCE_TABLE;

        $controls = [];
        if ($inlineConfiguration['appearance']['elementBrowserEnabled'] ?? true) {
            if ($inlineConfiguration['appearance']['createNewRelationLinkTitle'] ?? false) {
                $buttonText = $inlineConfiguration['appearance']['createNewRelationLinkTitle'];
            } else {
                $buttonText = 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:cm.createNewRelation';
            }
            $buttonText = $languageService->sL($buttonText);
            $attributes = [
                'type' => 'button',
                'class' => 'btn btn-default t3js-element-browser',
                'style' => !($inlineConfiguration['inline']['showCreateNewRelationButton'] ?? true) ? 'display: none;' : '',
                'title' => $buttonText,
                'data-mode' => 'file',
                'data-params' => '|||' . implode(',', $allowedFileTypes) . '|' . $objectPrefix,
            ];
            $controls[] = '
                <button ' . GeneralUtility::implodeAttributes($attributes, true) . '>
				    ' . $this->iconFactory->getIcon('actions-insert-record', Icon::SIZE_SMALL)->render() . '
				    ' . htmlspecialchars($buttonText) . '
			    </button>';
        }

        $onlineMediaAllowed = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class)->getSupportedFileExtensions();
        if ($allowedFileTypes !== []) {
            $onlineMediaAllowed = array_intersect($allowedFileTypes, $onlineMediaAllowed);
        }

        $showUpload = (bool)($inlineConfiguration['appearance']['fileUploadAllowed'] ?? true);
        $showByUrl = ($inlineConfiguration['appearance']['fileByUrlAllowed'] ?? true) && $onlineMediaAllowed !== [];

        if (($showUpload || $showByUrl) && ($backendUser->uc['edit_docModuleUpload'] ?? false)) {
            $defaultUploadFolderResolver = GeneralUtility::makeInstance(DefaultUploadFolderResolver::class);
            $folder = $defaultUploadFolderResolver->resolve(
                $backendUser,
                $this->data['tableName'] === 'pages' ? $this->data['vanillaUid'] : ($this->data['parentPageRow']['uid'] ?? 0),
                $this->data['tableName'],
                $this->data['fieldName']
            );
            if (
                $folder instanceof Folder
                && $folder->getStorage()->checkUserActionPermission('add', 'File')
            ) {
                if ($showUpload) {
                    if ($inlineConfiguration['appearance']['uploadFilesLinkTitle'] ?? false) {
                        $buttonText = $inlineConfiguration['appearance']['uploadFilesLinkTitle'];
                    } else {
                        $buttonText = 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:file_upload.select-and-submit';
                    }
                    $buttonText = $languageService->sL($buttonText);

                    $attributes = [
                        'type' => 'button',
                        'class' => 'btn btn-default t3js-drag-uploader',
                        'title' => $buttonText,
                        'style' => !($inlineConfiguration['inline']['showCreateNewRelationButton'] ?? true) ? 'display: none;' : '',
                        'data-dropzone-target' => '#' . StringUtility::escapeCssSelector($currentStructureDomObjectIdPrefix),
                        'data-insert-dropzone-before' => '1',
                        'data-file-irre-object' => $objectPrefix,
                        'data-file-allowed' => implode(',', $allowedFileTypes),
                        'data-target-folder' => $folder->getCombinedIdentifier(),
                        'data-max-file-size' => (string)(GeneralUtility::getMaxUploadFileSize() * 1024),
                    ];
                    $controls[] = '
                        <button ' . GeneralUtility::implodeAttributes($attributes, true) . '>
					        ' . $this->iconFactory->getIcon('actions-upload', Icon::SIZE_SMALL)->render() . '
                            ' . htmlspecialchars($buttonText) . '
                        </button>';

                    $this->javaScriptModules[] = JavaScriptModuleInstruction::create('@typo3/backend/drag-uploader.js');
                }
                if ($showByUrl) {
                    if ($inlineConfiguration['appearance']['addMediaLinkTitle'] ?? false) {
                        $buttonText = $inlineConfiguration['appearance']['addMediaLinkTitle'];
                    } else {
                        $buttonText = 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:online_media.new_media.button';
                    }
                    $buttonText = $languageService->sL($buttonText);
                    $attributes = [
                        'type' => 'button',
                        'class' => 'btn btn-default t3js-online-media-add-btn',
                        'title' => $buttonText,
                        'style' => !($inlineConfiguration['inline']['showOnlineMediaAddButtonStyle'] ?? true) ? 'display: none;' : '',
                        'data-target-folder' => $folder->getCombinedIdentifier(),
                        'data-file-irre-object' => $objectPrefix,
                        'data-online-media-allowed' => implode(',', $onlineMediaAllowed),
                        'data-btn-submit' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:online_media.new_media.placeholder'),
                        'data-placeholder' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:online_media.new_media.placeholder'),
                        'data-online-media-allowed-help-text' => $languageService->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:cm.allowEmbedSources'),
                    ];

                    // @todo Should be implemented as web component
                    $controls[] = '
                        <button ' . GeneralUtility::implodeAttributes($attributes, true) . '>
							' . $this->iconFactory->getIcon('actions-online-media-add', Icon::SIZE_SMALL)->render() . '
							' . htmlspecialchars($buttonText) . '
                        </button>';

                    $this->javaScriptModules[] = JavaScriptModuleInstruction::create('@typo3/backend/online-media.js');
                }
            }


            /*
            <?php
                $uid = uniqid();
                ?>
                <span class="btn btn-default pixxio" data-uid="<?php echo $uid ?>" style="margin-left: 5px">
                <?php echo $this->iconFactory->getIcon('actions-pixxio-extension-modal-view', \TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL)->render() ?>
                <?php echo $buttonText ?>
                </span>
                <script src="/typo3conf/ext/pixxio_extension/Resources/Public/Vendor/pixxio.jsdk.min.js" defer async></script>
                <style>
                @import "/typo3conf/ext/pixxio_extension/Resources/Public/Vendor/pixxio.jsdk.css";
                </style>
                <div class="pixxio-jsdk" data-uid="<?php echo $uid ?>" style="z-index: 1000; position: relative;"
                data-dom="<?php echo htmlspecialchars($objectPrefix)  ?>"
                data-key="<?php echo $this->applikationKey ?: '' ?>"
                data-url="<?php echo $extensionConfiguration['url'] ?: '' ?>" 
                data-token="<?php echo $extensionConfiguration['token_refresh'] ?: '' ?>"
                ></div>
             */

             $extensionConfiguration = \Pixxio\PixxioExtension\Utility\ConfigurationUtility::getExtensionConfiguration();

             $languageService = $this->getLanguageService();
             $buttonText = htmlspecialchars($languageService->sL('LLL:EXT:pixxio_extension/Resources/Private/Language/locallang_be.xlf:modal_view.button'));
     
             $foreign_table = $inlineConfiguration['foreign_table'];
             $currentStructureDomObjectIdPrefix = $this->inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($this->data['inlineFirstPid']);
             $objectPrefix = $currentStructureDomObjectIdPrefix . '-' . $foreign_table;

            $attributes = [
                'type' => 'button',
                'class' => 'btn btn-default pixxio pixxio-jsdk',
                'title' => $buttonText,
                'style' => 'margin-left:5px',
                'data-dom' => htmlspecialchars($objectPrefix),
                'data-key'=> $this->applikationKey,
                'data-url' => $extensionConfiguration['url'], 
                'data-token' => $extensionConfiguration['token_refresh'],
                'data-uid' => uniqid()
            ];

            // @todo Should be implemented as web component
            $controls[] = '
                <button ' . GeneralUtility::implodeAttributes($attributes, true) . '>
                    ' . $this->iconFactory->getIcon('actions-online-media-add', Icon::SIZE_SMALL)->render() . '
                    ' . htmlspecialchars($buttonText) . '
                </button>';


            $iframe_lang = 'de';

            $iframe_url = 'https://plugin.pixx.io/static/v1/' . $iframe_lang . '/media?applicationId=eS9Pb3S5bsEa2Z6527lUwUBp8';
            // https://plugin.pixx.io/static/v1/de/media?applicationId=eS9Pb3S5bsEa2Z6527lUwUBp8

            $controls[] = '
            <div id="pixxio-lightbox"><div class="pixxio-close"></div><div class="pixxio-lightbox-inner"></div><iframe id="pixxio_sdk" data-src="'.$iframe_url .'" width="100%" height="100%"></iframe></div></div>';

            $this->javaScriptModules[] = JavaScriptModuleInstruction::create('/typo3conf/ext/pixxio_extension/Resources/Public/JavaScript/ScriptSDK.js');
            $assetsCollector = GeneralUtility::makeInstance(AssetCollector::class);
            $assetsCollector->addStylesheet('pixxio_extension','EXT:pixxio_extension/Resources/Public/Stylesheet/StyleSDK.css');

            //$this->javaScriptModules[] = JavaScriptModuleInstruction::create('/typo3conf/ext/pixxio_extension/Resources/Public/JavaScript/pixxio_extension.js');
            //echo GeneralUtility::wrapJS($script);

            /*
            $allowedFileTypes = array(
                    'jpg',
                    'png',
                    'tiff',
                    'heic',
            );

            $allowedDownloadFormats = array(
                    'png',
                    'jpg',
                    'preview'
            );

            $controls[] = '<iframe id="pixxio_sdk" src="'.$iframe_url .'" width="100%" height="100%"></iframe>';
            */

            // check SVG support and add parameters
            /*
            $allowedExtensions = array_keys( get_allowed_mime_types() );
            if ( in_array( 'svg' , $allowedExtensions ) ) {
                $allowedFileTypes[] = 'svg';
                $allowedDownloadFormats[] = 'svg';
            }

            foreach ( $allowedFileTypes as $fileType ) {
                $iframe_url .= '&allowedFileTypes=' . $fileType;
            }

            foreach ( $allowedDownloadFormats as $downloadFormat ) {
                $iframe_url .= '&allowedDownloadFormats='. $downloadFormat;
            }
            */

            // $this->javaScriptModules[] = JavaScriptModuleInstruction::create('/typo3conf/ext/pixxio_extension/Resources/Public/Vendor/pixxio.jsdk.min.js');

        }

        return $controls;
    }

    /**
     * Extracts FlexForm parts of a form element name like
     * data[table][uid][field][sDEF][lDEF][FlexForm][vDEF]
     */
    protected function extractFlexFormParts(string $formElementName): ?array
    {
        $flexFormParts = null;
        $matches = [];
        if (preg_match('#^data(?:\[[^]]+\]){3}(\[data\](?:\[[^]]+\]){4,})$#', $formElementName, $matches)) {
            $flexFormParts = GeneralUtility::trimExplode(
                '][',
                trim($matches[1], '[]')
            );
        }
        return $flexFormParts;
    }

    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}