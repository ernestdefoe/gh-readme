import { Admin } from 'flarum/common/extenders';

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
      label: 'GitHub Personal Access Token (optional)',
      placeholder: 'ghp_…',
      help: "Used to raise GitHub's API rate limit from 60/hour-per-IP (unauthenticated) to 5000/hour. No scopes required for public repos. Generate at https://github.com/settings/tokens.",
    }))
    .setting(() => ({
      setting: 'gh-readme.cache_minutes',
      type: 'number',
      min: 1,
      max: 60,
      label: 'Cache duration (minutes)',
      placeholder: '10',
      help: 'How long the server caches each README before refetching. Clamped 1–60.',
    })),
];
