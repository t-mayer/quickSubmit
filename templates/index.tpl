{**
 * templates/index.tpl
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Template for one-page submission form
 *}

{extends file="layouts/backend.tpl"}
{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>

	<script type="text/javascript">
		$(function() {ldelim}
		// Attach the form handler.
		$('#quickSubmitForm').pkpHandler('$.pkp.controllers.form.FormHandler');
		{rdelim});
	</script>

	<div id="quickSubmitPlugin" class="app__contentPanel">

		<p>{translate key="plugins.importexport.quickSubmit.descriptionLong"}</p>

		<form class="pkp_form" id="quickSubmitForm" method="post" action="{plugin_url path="saveSubmit"}">
			<input type="hidden" name="reloadForm" id="reloadForm" value="0" />

			{if $submissionId}
				<input type="hidden" name="submissionId" value="{$submissionId|escape}" />
			{/if}

			{csrf}
			{include file="controllers/notification/inPlaceNotification.tpl" notificationId="quickSubmitFormNotification"}

			{fbvFormSection label="editor.issues.coverPage" class=$wizardClass}
			<div id="{$openCoverImageLinkAction->getId()}" class="pkp_linkActions">
				{include file="linkAction/linkAction.tpl" action=$openCoverImageLinkAction contextId="quickSubmitForm"}
			</div>
			{/fbvFormSection}

			{* There is only one supported submission locale; choose it invisibly *}
			{if count($supportedSubmissionLocaleNames) == 1}
				{foreach from=$supportedSubmissionLocaleNames item=localeName key=locale}
					{fbvElement type="hidden" id="locale" value=$locale}
				{/foreach}

			{* There are several submission locales available; allow choice *}
			{else}
				{fbvFormSection title="submission.submit.submissionLocale" size=$fbvStyles.size.MEDIUM for="locale"}
				{fbvElement label="submission.submit.submissionLocaleDescription" required="true" type="select" id="locale" from=$supportedSubmissionLocaleNames selected=$locale translate=false}
				{/fbvFormSection}
			{/if}

			{* Work type *}
			{fbvFormArea id="workType"}

			<!-- Submission Type -->
			{fbvFormSection list="true" label="submission.workflowType" description="submission.workflowType.description"}
				{fbvElement type="radio" name="workType" id="isEditedVolume-0" value=$smarty.const.WORK_TYPE_AUTHORED_WORK checked=$workType|compare:$smarty.const.WORK_TYPE_EDITED_VOLUME:false:true label="submission.workflowType.authoredWork"}{* "checked" is inverted; matches empty and WORK_TYPE_AUTHORED_WORK *}
				{fbvElement type="radio" name="workType" id="isEditedVolume-1" value=$smarty.const.WORK_TYPE_EDITED_VOLUME checked=$workType|compare:$smarty.const.WORK_TYPE_EDITED_VOLUME label="submission.workflowType.editedVolume"}
			{/fbvFormSection}
			{/fbvFormArea}

			{* Series and categories. *}
			{if count($categoryOptions)}
				{if $readOnly}
					{fbvFormSection title="grid.category.categories" list=true}
						{foreach from=$categoryOptions item="category" key="id"}
							{if in_array($id, $assignedCategories)}
								<li>{$category->getLocalizedTitle()|escape}</li>
							{/if}
						{/foreach}
					{/fbvFormSection}
				{else}
					{fbvFormSection list=true title="grid.category.categories"}
						{foreach from=$categoryOptions item="category" key="id"}
							{fbvElement type="checkbox" id="categories[]" value=$id checked=in_array($id, $assignedCategories) label=$category translate=false}
						{/foreach}
					{/fbvFormSection}
				{/if}
			{/if}

			{* Series. *}	
			{include file="submission/form/series.tpl"}

			{* Title, Abstract, etc.*}
			{include file="core:submission/submissionMetadataFormTitleFields.tpl"}
			{include file="submission/submissionMetadataFormFields.tpl"}

			{* Author form. *}
			{fbvFormArea id="contributors"}
			<!--  Contributors -->
				{capture assign=authorGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.users.author.AuthorGridHandler" op="fetchGrid" submissionId=$submissionId publicationId=$publicationId escape=false}{/capture}
				{load_url_in_div id="authorsGridContainer" url=$authorGridUrl}
				{$additionalContributorsFields}
			{/fbvFormArea}

			{* Publication formats. *}
			{fbvFormArea id="publication-formats"}
				{capture assign=representationsGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.catalogEntry.PublicationFormatGridHandler" op="fetchGrid" submissionId=$submissionId publicationId=$publicationId stageId=$smarty.const.WORKFLOW_STAGE_ID_PRODUCTION escape=false}{/capture}
				{load_url_in_div id="formatsGridContainer" url=$representationsGridUrl}
			{/fbvFormArea}

			{* Chapters. *}
			{fbvFormArea id="chapters"}
				<!--  Chapters -->
				{capture assign=chaptersGridUrl}{url router=$smarty.const.ROUTE_COMPONENT  component="grid.users.chapter.ChapterGridHandler" op="fetchGrid" submissionId=$submissionId publicationId=$publicationId escape=false}{/capture}
				{load_url_in_div id="chaptersGridContainer" url=$chaptersGridUrl}
			{/fbvFormArea}
		
			{* Buttons. *}
			{capture assign="cancelUrl"}{plugin_url path="cancelSubmit" submissionId="$submissionId"}{/capture}
			{fbvFormButtons id="quickSubmit" submitText="common.save" cancelUrl=$cancelUrl cancelUrlTarget="_self"}
		</form>
	</div>
{/block}