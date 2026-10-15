import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';

const t = (key) => app.translator.trans('ernestdefoe-gh-readme.admin.settings.' + key);

/**
 * Admin settings.
 *
 *   gh-readme.github_token   — optional Personal Access Token. Lets
 *     the extension hit GitHub's authenticated rate limit
 *     (5000/hour) instead of the 60/hour-per-IP unauth limit. No
 *     scopes needed for public repos.
 *
 *   gh-readme.cache_minutes  — how long the server caches each
 *     repo's processed README. Defaults to 10. Clamped 1–60 in the
 *     fetcher so a misconfiguration can't stall fresh fetches.
 */
export default [
  new Admin()
    .setting(() => ({
      setting: 'gh-readme.github_token',
      type: 'password',
      label: t('token_label'),
      placeholder: 'ghp_…',
      help: t('token_help'),
    }))
    .setting(() => ({
      setting: 'gh-readme.cache_minutes',
      type: 'number',
      min: 1,
      max: 60,
      label: t('cache_label'),
      placeholder: '10',
      help: t('cache_help'),
    })),
];
