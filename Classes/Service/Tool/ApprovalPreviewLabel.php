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

    // --- Shared: the object, its place and language, one field's change ---
    case ObjectPage          = 'approvalPreview.object.page';
    case ObjectContent       = 'approvalPreview.object.content';
    case ObjectFile          = 'approvalPreview.object.file';
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

    // --- The texts of a file: its metadata and a reference's own ---
    case FileFieldTitle       = 'approvalPreview.fileField.title';
    case FileFieldAlternative = 'approvalPreview.fileField.alternative';
    case FileFieldDescription = 'approvalPreview.fileField.description';
    case FileFieldCopyright   = 'approvalPreview.fileField.copyright';

    // --- set_file_alternative_text, update_fal_asset_meta ---
    case SetAlternativeTextHeading = 'approvalPreview.setAlternativeText.heading';
    case UpdateFileMetadataHeading = 'approvalPreview.updateFileMetadata.heading';

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
     */
    public function reference(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:' . $this->value;
    }
}
