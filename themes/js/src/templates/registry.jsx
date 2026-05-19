/**
 * Template + block-component registry.
 *
 * Three slots:
 *
 * 1. `pageTemplates` — keyed by page slug. The TemplateResolver checks this
 *    BEFORE falling through to the generic Page template.
 *
 * 2. `singleTemplates` — keyed by custom post type. Used when rendering a
 *    singular request for that post type.
 *
 * 3. `blockComponents` — keyed by Gutenberg block name (e.g. `core/gallery`,
 *    `my-plugin/slider`). When `<BlockContent>` parses `post.content.rendered`
 *    and encounters a block whose name appears here, it renders the React
 *    component instead of the original HTML.
 *
 *    Each block component receives:
 *      - runtime    — the full WP_HEADLESS runtime
 *      - attrs      — block attributes (the JSON from the block's annotation)
 *      - innerHTML  — the rendered HTML inside the block wrapper
 *      - className  — the wrapper's original class attribute
 *
 *    Example:
 *
 *      // src/components/blocks/slider.jsx
 *      export function Slider({ attrs, innerHTML }) {
 *        // attrs.slides, attrs.duration, etc. come from the block.json
 *        return <MyReactSlider slides={attrs.slides} />
 *      }
 *
 *      // src/templates/registry.js
 *      import { Slider } from '../components/blocks/slider'
 *      export const blockComponents = {
 *        'my-plugin/slider': Slider,
 *      }
 */

/** @type {Record<string, React.ComponentType<any>>} */
export const pageTemplates = {
  // 'about': PageAbout,
  // 'contact': PageContact,
}

/** @type {Record<string, React.ComponentType<any>>} */
export const singleTemplates = {
  // 'book': SingleBook,
  // 'product': SingleProduct,
}

/** @type {Record<string, React.ComponentType<any>>} */
export const blockComponents = {
  // 'core/gallery': ReactGallery,
  // 'my-plugin/slider': SliderBlock,
}
