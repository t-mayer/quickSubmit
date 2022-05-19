<?php

/**
 * @file QuickSubmitForm.inc.php
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class QuickSubmitForm
 * @ingroup plugins_importexport_quickSubmit
 *
 * @brief Form for QuickSubmit one-page submission plugin
 */


import('lib.pkp.classes.form.Form');
import('classes.submission.SubmissionMetadataFormImplementation');
import('classes.publication.Publication');

class QuickSubmitForm extends Form {
	/** @var Request */
	protected $_request;

	/** @var Submission */
	protected $_submission;

	/** @var Press */
	protected $context;

	/** @var SubmissionMetadataFormImplementation */
	protected $_metadataFormImplem;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $request object
	 */
	function __construct($plugin, $request) {
		parent::__construct($plugin->getTemplateResource('index.tpl'));
		$this->_request = $request;
		$this->_context = $request->getContext();
		$this->_metadataFormImplem = new SubmissionMetadataFormImplementation($this);

		$locale = $request->getUserVar('locale');
		if ($locale && ($locale != AppLocale::getLocale())) {
			$this->setDefaultFormLocale($locale);
		}

		// Get current submission object and add checks.
		if ($submissionId = $request->getUserVar('submissionId')) {

			// Get DAOs.
			$submissionDao = DAORegistry::getDAO('SubmissionDAO');
			$publicationDao = DAORegistry::getDAO('PublicationDAO'); 

			// Set submission.
			$this->_submission = $submissionDao->getById($submissionId);
			if ($this->_submission->getContextId() != $this->_context->getId()) throw new Exeption('Submission not in context!');
			$this->_submission->setLocale($this->getDefaultFormLocale());

			// Set publication.
			$publication = $this->_submission->getCurrentPublication();
			$publication->setData('locale', $this->getDefaultFormLocale());
			$publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));

			// Update objects.
			$submissionDao->updateObject($this->_submission);
			$publicationDao->updateObject($publication);

			// Add checks.
			$this->_metadataFormImplem->addChecks($this->_submission);
		}
		
		// Add additional checks.
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
		$this->addCheck(new FormValidatorCustom($this, 'seriesId', 'optional', 'author.submit.seriesRequired', array(DAORegistry::getDAO('SeriesDAO'), 'getById'), array($this->_context ->getId())));


		// Validation checks for this form
		$supportedSubmissionLocales = $this->_context->getSupportedSubmissionLocales();
		if (!is_array($supportedSubmissionLocales) || count($supportedSubmissionLocales) < 1)
			$supportedSubmissionLocales = array($this->_context->getPrimaryLocale());
		$this->addCheck(new FormValidatorInSet($this, 'locale', 'required', 'submission.submit.form.localeRequired', $supportedSubmissionLocales));
	}

	/**
	 * Get the submission associated with the form.
	 * @return Submission
	 */
	function getSubmission() {
		return $this->_submission;
	}

	/**
	 * Get the names of fields for which data should be localized.
	 * @return array
	 */
	function getLocaleFieldNames() {
		return $this->_metadataFormImplem->getLocaleFieldNames();
	}

	/**
	 * Display the form.
	 */
	function display($request = null, $template = null) {

		// Fetch template manager, assign supported locales.
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(
			'supportedSubmissionLocaleNames',
			$this->_context->getSupportedSubmissionLocaleNames()
		);

		// Tell the form what fields are enabled (and which of those are required).
		foreach (Application::getMetadataFields() as $field) {
			$templateMgr->assign(array(
				$field . 'Enabled' => in_array($this->_context->getData($field), array(METADATA_ENABLE, METADATA_REQUEST, METADATA_REQUIRE)),
				$field . 'Required' => $this->_context->getData($field) === METADATA_REQUIRE,
			));
		}

		// Cover image link action.
		import('lib.pkp.classes.linkAction.LinkAction');
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$locale = AppLocale::getLocale();
		$router = $this->_request->getRouter();
		$currentPublication = $this->_submission->getCurrentPublication();
		$templateMgr->assign('openCoverImageLinkAction', new LinkAction(
			'uploadFile',
			new AjaxModal(
				$router->url($this->_request, null, null, 'importexport', array('plugin', 'QuickSubmitPlugin', 'uploadCoverImage'), array(
					'coverImage' => $this->_submission->getCoverImage($locale),
					'submissionId' => $this->_submission->getId(),
					'publicationId' => $this->_submission->getCurrentPublication()->getId(),
					// This action can be performed during any stage,
					// but we have to provide a stage id to make calls
					// to IssueEntryTabHandler
					'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
				)),
				__('common.upload'),
				'modal_add_file'
			),
			__('common.upload'),
			'add'
		));

		// Get series for this context and pass to template manager.
		$seriesTitles = array();
		$seriesDao = DAORegistry::getDAO('SeriesDAO');
		$series =  $seriesDao->getByContextId($this->_context->getId());
		$seriesTitlesArray = $series->toAssociativeArray();
		foreach ($seriesTitlesArray as $series) {
			$seriesTitles[$series->getId()] = $series->getLocalizedTitle();
		}
		$seriesOptions = array('' => __('submission.submit.selectSeries')) + $seriesTitles;
		$templateMgr->assign('seriesOptions', $seriesOptions);

		// Pass other necessary variables to template.
		$templateMgr->assign(array(
			'submission' => $this->_submission,
			'locale' => $this->getDefaultFormLocale(),
			'publicationId' => $currentPublication->getId(),
			'submissionId' => $this->_submission->getId(),
		));

		// Get available category options for this context and pass to template.
		$categoryOptions = [];
		$categories = DAORegistry::getDAO('CategoryDAO')->getByContextId($this->_context->getId())->toAssociativeArray();
		foreach ($categories as $category) {
			$label = $category->getLocalizedTitle();
			if ($category->getParentId()) {
				$label = $categories[$category->getParentId()]->getLocalizedTitle() . ' > ' . $label;
			}
			$categoryOptions[(int) $category->getId()] = $label;
		}

		// Get assigned categories (for checked boxes in template).
		$assignedCategories = [];
		if ($this->getData('categories')) {
			$checkedCategories = $this->getData('categories');
			foreach ($checkedCategories as $key => $categoryId) {
				$assignedCategories[] = (int) $categoryId;
			}
		}
		$templateMgr->assign(array(
			'assignedCategories' => $assignedCategories,
			'categoryOptions' => $categoryOptions,
		));

		// Process entered tagit fields values for redisplay.
		// @see PKPSubmissionHandler::saveStep
		$tagitKeywords = $this->getData('keywords');
		if (is_array($tagitKeywords)) {
			$tagitFieldNames = $this->_metadataFormImplem->getTagitFieldNames();
			$locales = array_keys($this->supportedLocales);
			$formTagitData = array();
			foreach ($tagitFieldNames as $tagitFieldName) {
				foreach ($locales as $locale) {
					$formTagitData[$locale] = array_key_exists($locale . "-$tagitFieldName", $tagitKeywords) ? $tagitKeywords[$locale . "-$tagitFieldName"] : array();
				}
				$this->setData($tagitFieldName, $formTagitData);
			}
		}

		parent::display($request, $template);
	}

	/**
	 * Initialize form data for a new form.
	 */
	function initData() {
		$this->_data = array();
		
		if (!$this->_submission) {
			$this->_data['locale'] = $this->getDefaultFormLocale();

			// Create and insert a new submission.
			$submissionDao = DAORegistry::getDAO('SubmissionDAO');
			$this->_submission = $submissionDao->newDataObject();
			$this->_submission->setContextId($this->_context->getId());
			$this->_submission->setStatus(STATUS_QUEUED);
			$this->_submission->setSubmissionProgress(1);
			$this->_submission->stampStatusModified();
			$this->_submission->setStageId(WORKFLOW_STAGE_ID_SUBMISSION);
			$this->_submission->setLocale($this->getDefaultFormLocale());
		
			// Insert the submission.
			$this->_submission = Services::get('submission')->add($this->_submission, $this->_request);
			$this->setData('submissionId', $this->_submission->getId());

			// Create and insert publication.
			$publication = new Publication();
			$publication->setData('submissionId', $this->_submission->getId());
			$publication->setData('locale', $this->getDefaultFormLocale());
			$publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));
			$publication->setData('status', STATUS_QUEUED);
			$publication->setData('version', 1);
			$publication = Services::get('publication')->add($publication, $this->_request);

			// Update submisson.
			$this->_submission = Services::get('submission')->edit($this->_submission, ['currentPublicationId' => $publication->getId()], $this->_request);
			
			// Initialize form data from current submission.
			$this->_metadataFormImplem->initData($this->_submission);

			// Add the user manager group (first that is found) to the stage_assignment for that submission.
			$user = $this->_request->getUser();
			$userGroupAssignmentDao = DAORegistry::getDAO('UserGroupAssignmentDAO');
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

			$userGroupId = null;
			$managerUserGroupAssignments = $userGroupAssignmentDao->getByUserId($user->getId(), $this->_context->getId(), ROLE_ID_MANAGER);
			if($managerUserGroupAssignments) {
				while($managerUserGroupAssignment = $managerUserGroupAssignments->next()) {
					$managerUserGroup = $userGroupDao->getById($managerUserGroupAssignment->getUserGroupId());
					$userGroupId = $managerUserGroup->getId();
					break;
				}
			}

			// Assign the user author to the stage.
			$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
			$stageAssignmentDao->build($this->_submission->getId(), $userGroupId, $user->getId());
		}
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->_metadataFormImplem->readInputData();
		$this->readUserVars(
			array(
				'workType',
				'seriesId',
				'categories',
			)
		);
	}

	/**
	 * Cancel submit.
	 */
	function cancel() {
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($this->getData('submissionId'));
		if ($this->_submission->getData('contextId') != $this->_context->getId()) throw new Exeption('Submission not in context!');
		if ($submission) $submissionDao->deleteById($submission->getId());
	}

	/**
	 * Save settings.
	 */
	function execute(...$functionParams) {

		// Execute submission metadata related operations.
		$this->_metadataFormImplem->execute($this->_submission, $this->_request);

		// Set submission data.
		$this->_submission->setData('locale', $this->getData('locale'));
		$this->_submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
		$this->_submission->setData('dateSubmitted', Core::getCurrentDate());
		$this->_submission->setData('submissionProgress', 0);
		$this->_submission->setData('seriesId', empty($this->getData('seriesId')) ? null : (int) $this->getData('seriesId'));
		$this->_submission->setData('categoryIds', $this->getData('categories')); 
		$this->_submission->setData('workType', $this->getData('workType'));
		parent::execute($this->_submission, ...$functionParams);

		// Update submission object.
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$submissionDao->updateObject($this->_submission);
		$this->_submission = $submissionDao->getById($this->_submission->getId());

		// Update publication object with user-entered values from form.
		$params = [];
		$publication = $this->_submission->getCurrentPublication();

		if ($this->getData('seriesId')) {
			   $params['seriesId']  = empty($this->getData('seriesId')) ? null : (int) $this->getData('seriesId');
		}
		if ($this->getData('workType')) {
			$params['workType']  = (int) $this->getData('workType');
	 	}
		if ($this->getData('categories')) {
			$params['categoryIds']  = $this->getData('categories');
	 	}
		$publication = Services::get('publication')->edit($publication, $params, $this->_request);

		// Index monograph.
		Application::getSubmissionSearchIndex()->submissionMetadataChanged($this->_submission);
		Application::getSubmissionSearchIndex()->submissionFilesChanged($this->_submission);
		Application::getSubmissionSearchIndex()->submissionChangesFinished();
	}
}