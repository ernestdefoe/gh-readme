import app from 'flarum/admin/app';

app.initializers.add('ernestdefoe-gh-readme', () => {
  /* No initializer-side wiring needed; the Admin extender in
   * ./extend.js registers the settings declaratively. */
});

export { default as extend } from './extend';
