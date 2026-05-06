// English translations.
//
// See cs.js for conventions. Endonyms (`Čeština`, `English`) are
// intentionally identical in both dictionaries — a user who accidentally
// switches to English UI must still recognize the Czech option.

export default {
  // ── Common ──────────────────────────────────────────────────────────────
  'common.cancel': 'Cancel',
  'common.save': 'Save',
  'common.saving': 'Saving…',
  'common.close': 'Close',
  'common.add': 'Add',
  'common.edit': 'Edit',
  'common.delete': 'Delete',
  'common.confirm': 'Confirm',
  'common.ok': 'OK',
  'common.yes': 'Yes',
  'common.no': 'No',
  'common.loading': 'Loading…',
  'common.error': 'An error occurred',
  'common.unknownError': 'unknown error',
  'common.empty': 'No records',

  // ── Sidebar ─────────────────────────────────────────────────────────────
  'sidebar.language': 'Language',
  'sidebar.language.cs': 'Čeština',
  'sidebar.language.en': 'English',
  'sidebar.language.auto': 'Automatic',
  'sidebar.appearance': 'Appearance',
  'sidebar.appearance.light': 'Light',
  'sidebar.appearance.dark': 'Dark',
  'sidebar.appearance.auto': 'Auto',
  'sidebar.collapse': 'Collapse menu',
  'sidebar.expand': 'Expand menu',
  'sidebar.backToApp': 'Back to application',
  'sidebar.accountSettings': 'Account settings',
  'sidebar.appSettings': 'Application settings',
  'sidebar.logout': 'Log out',
  'sidebar.notAuthenticated': 'Not authenticated',
  'sidebar.navigationLoadFailed': 'Failed to load navigation',

  // ── App shell ───────────────────────────────────────────────────────────
  'app.selectMenuItem': 'Select an item from the menu',
  'app.unsupportedPanel': 'Unsupported panel type: {type}',
  'tabbar.close': 'Close {tab}',

  // ── Login ───────────────────────────────────────────────────────────────
  'login.heading': 'Shipard',
  'login.username': 'Login name',
  'login.password': 'Password',
  'login.submit': 'Sign in',
  'login.submitting': 'Signing in…',
  'login.failed': 'Sign-in failed.',
  'login.languagePicker.label': 'Language:',

  // ── Viewer (tabs, search, status, reanalyze dialog) ─────────────────────
  'viewer.tab.active': 'Active',
  'viewer.tab.archive': 'Archive',
  'viewer.tab.trash': 'Trash',
  'viewer.tab.all': 'All',
  'viewer.search.placeholder': 'Search…',
  'viewer.search.clear': 'Clear search',
  'viewer.endOfList': "That's all",
  'viewer.selectRecord': 'Select a record',
  'viewer.reanalyze.title': 'Re-analyze message',
  'viewer.reanalyze.body': 'Run AI analysis again? Existing extracted documents in states {states} will be marked as superseded. Documents you have already applied or rejected will remain unchanged.',
  'viewer.reanalyze.replaceableStates': 'To apply / Awaiting review / Low confidence',
  'viewer.reanalyze.profileLabel': 'Profile (optional — empty keeps the DS default):',
  'viewer.reanalyze.defaultProfile': '— DS default profile —',
  'viewer.reanalyze.submit': 'Run analysis',
  'viewer.reanalyze.submitting': 'Starting…',
  'viewer.reanalyze.failed': 'Failed to restart analysis: {msg}',

  // ── Viewer detail (extracted documents + reject dialog) ─────────────────
  'viewer.detail.empty': 'No details',
  'viewer.detail.noExtracted': 'No extracted documents.',
  'viewer.detail.applied': 'Applied: {date}',
  'viewer.detail.rejected': 'Rejection reason: {reason}',
  'viewer.detail.showDetail': 'Show detail',
  'viewer.detail.apply': 'Apply',
  'viewer.detail.reject': 'Reject',
  'viewer.detail.rejectTitle': 'Reject document',
  'viewer.detail.rejectReasonLabel': 'Rejection reason (required):',
  'viewer.detail.rejectReasonPlaceholder': 'E.g. False positive, misclassified type…',
  'viewer.detail.applyFailed': 'Failed to apply: {msg}',
  'viewer.detail.rejectFailed': 'Failed to reject: {msg}',

  // ── Form (FormDialog, FormEditor, FormRenderer, FormStateBar) ───────────
  'form.titleNew': 'New record',
  'form.unsavedChanges': 'You have unsaved changes. Really close the form?',
  'form.loadFailed': 'Failed to load form.',
  'form.saveFailed': 'Failed to save record.',
  'form.transitionFailed': 'Failed to change state.',
  'form.metaLoadFailed': 'Failed to load table metadata.',
  'form.recordLoadFailed': 'Failed to load record.',
  'form.saveUnknownError': 'An unknown error occurred while saving.',
  'form.groupOther': 'Other',

  // ── Form sub-table ──────────────────────────────────────────────────────
  'subtable.saveFirst': 'Save the record first, then you can add rows.',
  'subtable.confirmDelete': 'Really delete this record?',

  // ── Attachments ─────────────────────────────────────────────────────────
  'attachments.title': 'Attachments',
  'attachments.saveFirst': 'Save the record first, then you can add attachments.',
  'attachments.upload': 'Upload attachment',
  'attachments.count': '{count, plural, one {# attachment} other {# attachments}}',
  'attachments.loading': 'Loading attachments…',
  'attachments.dropHint': 'Drop files here or click "Upload attachment"',
  'attachments.action.download': 'Download',
  'attachments.action.rename': 'Rename',
  'attachments.confirmDelete': 'Really delete attachment "{name}"?',

  // ── Server error codes (mapped via i18n/errors.js translateError()) ─────
  'error.VALIDATION_ERROR': 'Validation failed',
  'error.NOT_FOUND': 'Record not found',
  'error.RECORD_NOT_FOUND': 'Record not found',
  'error.TABLE_NOT_FOUND': 'Table not found',
  'error.UNAUTHORIZED': 'You must be signed in to perform this action',
  'error.FORBIDDEN': 'Insufficient permissions',
  'error.BAD_REQUEST': 'Bad request',
  'error.METHOD_NOT_ALLOWED': 'Method not allowed',
  'error.INTERNAL_ERROR': 'Internal server error',
  'error.UPLOAD_ERROR': 'File upload failed',
  'error.NETWORK_ERROR': 'Network error — check your connection',

  // ── Table browser ───────────────────────────────────────────────────────
  'browser.addRecord': 'New record',
  'browser.fetchFailed': 'Error loading data',
  'browser.metaFetchFailed': 'Error loading metadata',
  'browser.pagination.info': 'Showing {start}–{end} of {total} records',
  'browser.pagination.prev': '← Previous',
  'browser.pagination.next': 'Next →',
  'browser.pagination.pageSize': 'Per page:',
};
