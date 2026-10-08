<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Every text an approval preview line is built from, one case per catalogue
 * entry (ADR-213).
 *
 * The value is the `trans-unit` id in `Resources/Private/Language/locallang.xlf`
 * and `de.locallang.xlf`. A closed enum instead of loose strings, so that a
 * test can walk {@see self::cases()} and fail when a case has no English or no
 * German text, and the reverse: an `approvalPreview.*` entry in the catalogue
 * that no case names.
 *
 * The placeholders (`%s`, `%d`) are part of the contract; the German text must
 * carry the same ones, in any order it needs ({@see ApprovalPreviewTranslator}
 * formats with `vsprintf()`, so `%1$s` positions are allowed).
 *
 * @internal
 */
enum ApprovalPreviewLabel: string
{
    // --- Shared ---
    case ValueEmpty  = 'approvalPreview.value.empty';
    case ValueNone   = 'approvalPreview.value.none';
    case ValueQuoted = 'approvalPreview.value.quoted';
    case ValueNothing = 'approvalPreview.value.nothing';

    // --- The card around a preview: a preview that failed, is empty, cut or withheld ---
    case CardFailed         = 'approvalPreview.card.failed';
    case CardFailedAtResume = 'approvalPreview.card.failedAtResume';
    case CardEmpty          = 'approvalPreview.card.empty';
    case CardUnavailable    = 'approvalPreview.card.unavailable';
    case CardOverflow       = 'approvalPreview.card.overflow';
    case CardWithheld       = 'approvalPreview.card.withheld';

    // --- Shared: the object, its place and language, one field's change ---
    case ObjectPage          = 'approvalPreview.object.page';
    case ObjectContent       = 'approvalPreview.object.content';
    case ObjectFile          = 'approvalPreview.object.file';
    case ObjectRecord        = 'approvalPreview.object.record';
    case FieldName           = 'approvalPreview.field.name';
    case VisibilityHiddenAtFirst = 'approvalPreview.visibility.hiddenAtFirst';
    case LocationOnPage      = 'approvalPreview.location.onPage';
    case ContentType         = 'approvalPreview.contentType';
    case LanguageDefault     = 'approvalPreview.language.default';
    case LanguageTranslation = 'approvalPreview.language.translation';
    case FieldUnchanged      = 'approvalPreview.field.unchanged';
    case FieldChange         = 'approvalPreview.field.change';
    case FieldChangedFrom    = 'approvalPreview.field.changedFrom';

    // --- Technical details: UIDs, table names, the things the main lines leave out ---
    case TechnicalDetails         = 'approvalPreview.technical.details';
    case TechnicalMore            = 'approvalPreview.technical.more';
    case TechnicalPage            = 'approvalPreview.technical.page';
    case TechnicalParentPage      = 'approvalPreview.technical.parentPage';
    case TechnicalFormerParent    = 'approvalPreview.technical.formerParentPage';
    case TechnicalAnchorPage      = 'approvalPreview.technical.anchorPage';
    case TechnicalExistingPage    = 'approvalPreview.technical.existingPage';
    case TechnicalSiteRootBefore  = 'approvalPreview.technical.siteRootBefore';
    case TechnicalSiteRootAfter   = 'approvalPreview.technical.siteRootAfter';
    case TechnicalRecord          = 'approvalPreview.technical.record';
    case TechnicalTranslations    = 'approvalPreview.technical.translations';
    case TechnicalSubpages        = 'approvalPreview.technical.subpages';
    case TechnicalLanguage        = 'approvalPreview.technical.language';
    case TechnicalFields          = 'approvalPreview.technical.fields';
    case TechnicalWholeValue      = 'approvalPreview.technical.wholeValue';
    case TechnicalContentType     = 'approvalPreview.technical.contentType';
    case TechnicalFile            = 'approvalPreview.technical.file';
    case TechnicalFileReferences  = 'approvalPreview.technical.fileReferences';
    case TechnicalFileReference   = 'approvalPreview.technical.fileReference';
    case TechnicalCurrentFile     = 'approvalPreview.technical.currentFile';
    case TechnicalTranslatedReferences = 'approvalPreview.technical.translatedReferences';
    case TechnicalTranslatedElements   = 'approvalPreview.technical.translatedElements';
    case TechnicalTargetPage      = 'approvalPreview.technical.targetPage';
    case TechnicalCurrentPage     = 'approvalPreview.technical.currentPage';
    case TechnicalColumn          = 'approvalPreview.technical.column';
    case TechnicalCurrentColumn   = 'approvalPreview.technical.currentColumn';
    case TechnicalAnchorElement   = 'approvalPreview.technical.anchorElement';
    case TechnicalHiddenField     = 'approvalPreview.technical.hiddenField';
    case TechnicalDefaultLanguageRecord = 'approvalPreview.technical.defaultLanguageRecord';
    case TechnicalUserGroups      = 'approvalPreview.technical.userGroups';
    case TechnicalExistingContent = 'approvalPreview.technical.existingContent';
    case TechnicalRecordType      = 'approvalPreview.technical.recordType';
    case TechnicalExistingTranslation = 'approvalPreview.technical.existingTranslation';
    case TechnicalTranslationService  = 'approvalPreview.technical.translationService';
    case TechnicalSite                = 'approvalPreview.technical.site';
    case TechnicalWithheldFields      = 'approvalPreview.technical.withheldFields';
    case TechnicalException           = 'approvalPreview.technical.exception';
    case TechnicalLanguagePair        = 'approvalPreview.technical.languagePair';

    // --- The texts of a file: its metadata and a reference's own ---
    case FileFieldTitle       = 'approvalPreview.fileField.title';
    case FileFieldAlternative = 'approvalPreview.fileField.alternative';
    case FileFieldDescription = 'approvalPreview.fileField.description';
    case FileFieldCopyright   = 'approvalPreview.fileField.copyright';

    // --- set_file_alternative_text, update_fal_asset_meta ---
    case SetAlternativeTextHeading = 'approvalPreview.setAlternativeText.heading';
    case UpdateFileMetadataHeading = 'approvalPreview.updateFileMetadata.heading';

    // --- set_page_social_image ---
    case SocialImageHeadingOpenGraph = 'approvalPreview.socialImage.headingOpenGraph';
    case SocialImageHeadingTwitter   = 'approvalPreview.socialImage.headingTwitter';
    case SocialImageCurrentNone      = 'approvalPreview.socialImage.currentNone';
    case SocialImageCurrent          = 'approvalPreview.socialImage.current';
    case SocialImageProposed         = 'approvalPreview.socialImage.proposed';
    case SocialImageReplaces         = 'approvalPreview.socialImage.replaces';

    // --- attach_file_to_content_element, attach_file_to_record ---
    case AttachFileHeadingContent = 'approvalPreview.attachFile.headingContent';
    case AttachFileHeadingRecord  = 'approvalPreview.attachFile.headingRecord';
    case AttachFileCount          = 'approvalPreview.attachFile.count';
    case AttachFileFile           = 'approvalPreview.attachFile.file';

    // --- replace_file_reference ---
    case ReplaceFileHeadingRemove         = 'approvalPreview.replaceFile.headingRemove';
    case ReplaceFileHeadingReplace        = 'approvalPreview.replaceFile.headingReplace';
    case ReplaceFilePosition              = 'approvalPreview.replaceFile.position';
    case ReplaceFileAfterwards            = 'approvalPreview.replaceFile.afterwards';
    case ReplaceFileFileStays             = 'approvalPreview.replaceFile.fileStays';
    case ReplaceFileCurrent               = 'approvalPreview.replaceFile.current';
    case ReplaceFileProposed              = 'approvalPreview.replaceFile.proposed';
    case ReplaceFileTextOwn               = 'approvalPreview.replaceFile.textOwn';
    case ReplaceFileTranslationsRemoved   = 'approvalPreview.replaceFile.translationsRemoved';
    case ReplaceFileTranslationsReplaced  = 'approvalPreview.replaceFile.translationsReplaced';
    case ReplaceFileOrphans               = 'approvalPreview.replaceFile.orphans';

    // --- move_content_element ---
    case MoveContentHeading  = 'approvalPreview.moveContent.heading';
    case MoveContentCurrent  = 'approvalPreview.moveContent.current';
    case MoveContentNewFirst = 'approvalPreview.moveContent.newFirst';
    case MoveContentNewAfter = 'approvalPreview.moveContent.newAfter';

    // --- copy_record ---
    case CopyPageHeading                    = 'approvalPreview.copyRecord.headingPage';
    case CopyContentHeading                 = 'approvalPreview.copyRecord.headingContent';
    case CopyTargetPageFirst                = 'approvalPreview.copyRecord.targetPageFirst';
    case CopyTargetPageAfter                = 'approvalPreview.copyRecord.targetPageAfter';
    case CopyTargetContentFirst             = 'approvalPreview.copyRecord.targetContentFirst';
    case CopyTargetContentAfter             = 'approvalPreview.copyRecord.targetContentAfter';
    case CopyPageAlong                      = 'approvalPreview.copyRecord.pageAlong';
    case CopyTranslationsPage               = 'approvalPreview.copyRecord.translationsPage';
    case CopyTranslationsContent            = 'approvalPreview.copyRecord.translationsContent';
    case CopyTranslationsPageBefore13425    = 'approvalPreview.copyRecord.translationsPageBefore13425';
    case CopyTranslationsContentBefore13425 = 'approvalPreview.copyRecord.translationsContentBefore13425';
    case CopyVisibility                     = 'approvalPreview.copyRecord.visibility';

    // --- publish_record ---
    case PublishHeadingPage    = 'approvalPreview.publish.headingPage';
    case PublishHeadingContent = 'approvalPreview.publish.headingContent';
    case PublishChange         = 'approvalPreview.publish.change';
    case PublishAlready        = 'approvalPreview.publish.already';
    case PublishStartTime      = 'approvalPreview.publish.startTime';
    case PublishStopTime       = 'approvalPreview.publish.stopTime';
    case PublishUserGroups     = 'approvalPreview.publish.userGroups';
    case PublishParentHidden   = 'approvalPreview.publish.parentHidden';

    // --- create_content_element_draft ---
    case CreateContentHeading         = 'approvalPreview.createContent.heading';
    case CreateContentPositionFirst   = 'approvalPreview.createContent.positionFirst';
    case CreateContentPositionAfter   = 'approvalPreview.createContent.positionAfter';
    case CreateContentImpact          = 'approvalPreview.createContent.impact';
    case CreateContentDuplicate       = 'approvalPreview.createContent.duplicate';
    case CreateContentDuplicateHidden = 'approvalPreview.createContent.duplicateHidden';

    // --- create_record_draft ---
    case CreateRecordHeading    = 'approvalPreview.createRecord.heading';
    case CreateRecordRecordType = 'approvalPreview.createRecord.recordType';
    case CreateRecordImpact     = 'approvalPreview.createRecord.impact';

    // --- create_translation_draft ---
    case TranslateHeading        = 'approvalPreview.translate.heading';
    case TranslateTargetLanguage = 'approvalPreview.translate.targetLanguage';
    case TranslateNew            = 'approvalPreview.translate.new';
    case TranslateMachine        = 'approvalPreview.translate.machine';
    case TranslateNoTextInSource = 'approvalPreview.translate.noTextInSource';
    case TranslateNoTextAllowed  = 'approvalPreview.translate.noTextAllowed';
    case TranslateGlossary       = 'approvalPreview.translate.glossary';
    case TranslateNoGlossary     = 'approvalPreview.translate.noGlossary';
    case TranslateWithheld       = 'approvalPreview.translate.withheld';
    case TranslateDiscards       = 'approvalPreview.translate.discards';
    case TranslateImpact         = 'approvalPreview.translate.impact';

    // --- fetch_external_url ---
    case FetchUrlHeading = 'approvalPreview.fetchUrl.heading';
    case FetchUrlAddress = 'approvalPreview.fetchUrl.address';
    case FetchUrlHost    = 'approvalPreview.fetchUrl.host';
    case FetchUrlQuery   = 'approvalPreview.fetchUrl.query';
    case FetchUrlNoQuery = 'approvalPreview.fetchUrl.noQuery';

    // --- update_page_metadata ---
    case UpdatePageHeading            = 'approvalPreview.updatePage.heading';
    case PageFieldTitle               = 'approvalPreview.pageField.title';
    case PageFieldSubtitle            = 'approvalPreview.pageField.subtitle';
    case PageFieldNavTitle            = 'approvalPreview.pageField.navTitle';
    case PageFieldAbstract            = 'approvalPreview.pageField.abstract';
    case PageFieldDescription         = 'approvalPreview.pageField.description';
    case PageFieldKeywords            = 'approvalPreview.pageField.keywords';
    case PageFieldSeoTitle            = 'approvalPreview.pageField.seoTitle';
    case PageFieldOgTitle             = 'approvalPreview.pageField.ogTitle';
    case PageFieldOgDescription       = 'approvalPreview.pageField.ogDescription';
    case PageFieldTwitterTitle        = 'approvalPreview.pageField.twitterTitle';
    case PageFieldTwitterDescription  = 'approvalPreview.pageField.twitterDescription';
    case PageFieldTwitterCard         = 'approvalPreview.pageField.twitterCard';

    // --- update_content_element ---
    case UpdateContentHeading = 'approvalPreview.updateContent.heading';

    // --- create_page_draft ---
    case CreatePageHeading        = 'approvalPreview.createPage.heading';
    case CreatePageLocation       = 'approvalPreview.createPage.location';
    case CreatePageTitle          = 'approvalPreview.createPage.pageTitle';
    case CreatePageNavTitle       = 'approvalPreview.createPage.navTitle';
    case CreatePageNavTitleSame   = 'approvalPreview.createPage.navTitleSame';
    case CreatePageType           = 'approvalPreview.createPage.pageType';
    case CreatePageLanguage       = 'approvalPreview.createPage.language';
    case CreatePagePositionFirst  = 'approvalPreview.createPage.positionFirst';
    case CreatePagePositionAfter  = 'approvalPreview.createPage.positionAfter';
    case CreatePageVisibility     = 'approvalPreview.createPage.visibility';
    case CreatePageImpact         = 'approvalPreview.createPage.impact';
    case CreatePageDuplicate      = 'approvalPreview.createPage.duplicate';
    case CreatePageDuplicateHidden = 'approvalPreview.createPage.duplicateHidden';

    // --- move_page ---
    case MovePageHeading          = 'approvalPreview.movePage.heading';
    case MovePagePage             = 'approvalPreview.movePage.page';
    case MovePageCurrent          = 'approvalPreview.movePage.current';
    case MovePageNewFirst         = 'approvalPreview.movePage.newFirst';
    case MovePageNewAfter         = 'approvalPreview.movePage.newAfter';
    case MovePageMovesAlong       = 'approvalPreview.movePage.movesAlong';
    case MovePageMovesAlongMany   = 'approvalPreview.movePage.movesAlongMany';
    case MovePageUrlUnchanged     = 'approvalPreview.movePage.urlUnchanged';
    case MovePageLeavesSite       = 'approvalPreview.movePage.leavesSite';
    case MovePageJoinsSite        = 'approvalPreview.movePage.joinsSite';
    case MovePageChangesSite      = 'approvalPreview.movePage.changesSite';

    // --- delete_record ---
    case DeletePageHeading        = 'approvalPreview.deleteRecord.headingPage';
    case DeleteContentHeading     = 'approvalPreview.deleteRecord.headingContent';
    case DeletePageObject         = 'approvalPreview.deleteRecord.objectPage';
    case DeleteContentObject      = 'approvalPreview.deleteRecord.objectContent';
    case DeleteLocation           = 'approvalPreview.deleteRecord.location';
    case DeleteLanguageDefault    = 'approvalPreview.deleteRecord.languageDefault';
    case DeleteLanguageTranslation = 'approvalPreview.deleteRecord.languageTranslation';
    case DeleteTranslationsNone   = 'approvalPreview.deleteRecord.translationsNone';
    case DeleteTranslationsSome   = 'approvalPreview.deleteRecord.translationsSome';
    case DeleteSubpagesNone       = 'approvalPreview.deleteRecord.subpagesNone';
    case DeleteSubpagesSome       = 'approvalPreview.deleteRecord.subpagesSome';
    case DeleteStoredNone         = 'approvalPreview.deleteRecord.storedNone';
    case DeleteStoredSome         = 'approvalPreview.deleteRecord.storedSome';
    case DeleteStoredUncountable  = 'approvalPreview.deleteRecord.storedUncountable';
    case DeleteTranslationContent = 'approvalPreview.deleteRecord.translationContent';
    case DeleteReferencesNone     = 'approvalPreview.deleteRecord.referencesNone';
    case DeleteReferencesSome     = 'approvalPreview.deleteRecord.referencesSome';
    case DeleteRecoverable        = 'approvalPreview.deleteRecord.recoverable';

    /**
     * The key as TYPO3 resolves it.
     *
     * @return non-empty-string
     */
    public function reference(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:' . $this->value;
    }

    /**
     * Whether this text is a card heading: the action a tool's successful
     * preview names in its first line (rule 16). Published to consumers through
     * {@see ApprovalPreviewHeadings}; a heading takes no placeholder, so a line
     * is one exactly when it equals the text.
     */
    public function isHeading(): bool
    {
        return match ($this) {
            self::SetAlternativeTextHeading,
            self::UpdateFileMetadataHeading,
            self::SocialImageHeadingOpenGraph,
            self::SocialImageHeadingTwitter,
            self::AttachFileHeadingContent,
            self::AttachFileHeadingRecord,
            self::ReplaceFileHeadingRemove,
            self::ReplaceFileHeadingReplace,
            self::MoveContentHeading,
            self::CopyPageHeading,
            self::CopyContentHeading,
            self::PublishHeadingPage,
            self::PublishHeadingContent,
            self::CreateContentHeading,
            self::CreateRecordHeading,
            self::TranslateHeading,
            self::FetchUrlHeading,
            self::UpdatePageHeading,
            self::UpdateContentHeading,
            self::CreatePageHeading,
            self::MovePageHeading,
            self::DeletePageHeading,
            self::DeleteContentHeading => true,
            default                    => false,
        };
    }
}
