<?php

/**
 * @file classes/form/UploadImageForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class UploadImageForm
 * @ingroup plugins_importexport_quicksubmit_classes_form
 *
 * @brief Form for uploading an image.
 */

import('lib.pkp.classes.form.Form');

class UploadImageForm extends Form {
	/** string Setting key that will be associated with the uploaded file. */
	var $_fileSettingName;

	/** @var $request object */
	var $request;

	/** @var $submissionId int */
	var $submissionId;

	/** @var $submission Submission */
	var $submission;

	/** @var $publication Publication */
	var $publication;

	/** @var $plugin QuickSubmitPlugin */
	var $plugin;

	/** @var $context Press */
	var $context;

	/**
	 * Constructor.
	 * @param $plugin object
	 * @param $request object
	 */
	function __construct($plugin, $request) {
		parent::__construct($plugin->getTemplateResource('uploadImageForm.tpl'));

		$this->addCheck(new FormValidator($this, 'temporaryFileId', 'required', 'manager.website.imageFileRequired'));

		$this->plugin = $plugin;
		$this->request = $request;
		$this->context = $request->getContext();

		$this->submissionId = $request->getUserVar('submissionId');

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$this->submission = $submissionDao->getById($request->getUserVar('submissionId'), $this->context->getId(), false);
		$this->publication = $this->submission->getCurrentPublication();
	}

	//
	// Extend methods from Form.
	//
	/**
	 * @copydoc Form::getLocaleFieldNames()
	 */
	function getLocaleFieldNames() {
		return array('altText');
	}

	/**
	 * @copydoc Form::initData()
	 */
	function initData() {
		$templateMgr = TemplateManager::getManager($this->request);
		$templateMgr->assign('submissionId', $this->submissionId);

		$locale = AppLocale::getLocale();
		$coverImage = $this->submission->getCoverImage($locale);
		$altText = $coverImage[$locale]['altText'];  

		if ($coverImage) {
			import('lib.pkp.classes.linkAction.LinkAction');
			import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
			$router = $this->request->getRouter();
			$deleteCoverImageLinkAction = new LinkAction(
				'deleteCoverImage',
				new RemoteActionConfirmationModal(
					$this->request->getSession(),
					__('common.confirmDelete'), null,
					$router->url($this->request, null, null, 'importexport', array('plugin', 'QuickSubmitPlugin', 'deleteCoverImage'), array(
						'coverImage' => $coverImage,
						'submissionId' => $this->submission->getId(),
						'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
					)),
					'modal_delete'
				),
				__('common.delete'),
				null
			);
			$templateMgr->assign('deleteCoverImageLinkAction', $deleteCoverImageLinkAction);
		}

		$this->setData('coverImage', $coverImage);
		$this->setData('altText', $altText);
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	function readInputData() {
		$this->readUserVars(array('altText', 'temporaryFileId'));
	}

	/**
	 * An action to delete a monograph cover image.
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	function deleteCoverImage($request) {
		assert($request->getUserVar('submissionId') != '');

		// Remove cover image and alt text from publication settings.
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */
		$locale = AppLocale::getLocale();
		$fileName = $this->publication->getData('coverImage')[$locale]['uploadName'];  
		$this->publication->setData('coverImage',[]);
		$publicationDao->updateObject($this->publication);


		// Remove the cover image file + thumbnail as well.
		$publicFileManager = new PublicFileManager();
		if ($publicFileManager->removeContextFile($this->submission->getContextId(), $fileName)) {
			$publicFileManager->removeContextFile($this->submission->getContextId(), Services::get('publication')->getThumbnailFileName($fileName));
			$json = new JSONMessage(true);
			$json->setEvent('fileDeleted');
			return $json;
		} else {
			return new JSONMessage(false, __('editor.article.removeCoverImageFileNotFound'));
		}
	}

	/**
	 * Save file image to Submission
	 * @param $request Request.
	 */
	function execute($request) {
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */

		$temporaryFile = $this->fetchTemporaryFile($request);
		$locale = AppLocale::getLocale();
		$coverImage = $this->publication->getData('coverImage');
		$submissionContext = $this->request->getContext();
		$user = $this->request->getUser();
		$userId = $user->getId();

		import('classes.file.PublicFileManager');
		$publicFileManager = new PublicFileManager();

		// File operations for cover image.
		if ($temporaryFile) {
			$type = $temporaryFile->getFileType();
			$extension = $publicFileManager->getImageExtension($type);
			if (!$extension) {
				return false;
			}
			$locale = AppLocale::getLocale();

			// Construct file name and move file to public directory.
			$fileName = join('_', ['submission', $this->submission->getId(), $this->publication->getId(), 'coverImage']); // eg - submission_1_1_coverImage
			
			// Move a temporary file to the context's public directory.
			$newFileName = Services::get('context')->moveTemporaryFile($submissionContext, $temporaryFile, $fileName, $userId, $locale);

			// Construct thumbnail.
			$coverImageFilePath = $publicFileManager->getContextFilesPath($submissionContext->getId()) . '/' . $newFileName;
			Services::get('publication')->makeThumbnail(
				$coverImageFilePath,
				Services::get('publication')->getThumbnailFileName($newFileName),
				$submissionContext->getData('coverThumbnailsMaxWidth'),
				$submissionContext->getData('coverThumbnailsMaxHeight')
			);
			
			// Set cover image.
			if ($newFileName) {
				$this->publication->setData('coverImage', [
					'altText' => !empty($this->getData('altText')) ? $this->getData('altText') : '',
					'dateUploaded' => \Core::getCurrentDate(),
					'uploadName' => $newFileName,
				], $locale);
				$publicationDao->updateObject($this->publication);
				return DAO::getDataChangedEvent();
			}
		} elseif ($coverImage) {
			$coverImage = $this->publication->getData('coverImage');
			$coverImage[$locale]['altText'] = $this->getData('altText');
			$this->publication->setData('coverImage', $coverImage);
			$publicationDao->updateObject($this->publication);
			return DAO::getDataChangedEvent();
		}
		return new JSONMessage(false, __('common.uploadFailed'));

	}

	/**
	 * Get the image that this form will upload a file to.
	 * @return string
	 */
	function getFileSettingName() {
		return $this->_fileSettingName;
	}

	/**
	 * Set the image that this form will upload a file to.
	 * @param $image string
	 */
	function setFileSettingName($fileSettingName) {
		$this->_fileSettingName = $fileSettingName;
	}


	//
	// Implement template methods from Form.
	//
	/**
	 * @see Form::fetch()
	 * @param $params template parameters
	 */
	function fetch($request, $template = null, $display = false, $params = null) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'fileSettingName' => $this->getFileSettingName(),
			'fileType' => 'image',
		));

		return parent::fetch($request, $template, $display);
	}


	//
	// Public methods
	//
	/**
	 * Fecth the temporary file.
	 * @param $request Request
	 * @return TemporaryFile
	 */
	function fetchTemporaryFile($request) {
		$user = $request->getUser();

		$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO');
		$temporaryFile = $temporaryFileDao->getTemporaryFile(
			$this->getData('temporaryFileId'),
			$user->getId()
		);
		return $temporaryFile;
	}

	/**
	 * Upload a temporary file.
	 * @param $request Request
	 */
	function uploadFile($request) {
		$user = $request->getUser();

		import('lib.pkp.classes.file.TemporaryFileManager');
		$temporaryFileManager = new TemporaryFileManager();
		$temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());

		if ($temporaryFile) return $temporaryFile->getId();

		return false;
	}
}

