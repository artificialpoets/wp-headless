import type { ComponentType } from 'react'

/**
 * Template + block-component registry.
 *
 * Three slots:
 *
 * 1. `pageTemplates` — keyed by page slug. Checked BEFORE the generic Page.
 * 2. `singleTemplates` — keyed by custom post type. For singular requests.
 * 3. `blockComponents` — keyed by Gutenberg block name. <BlockContent>
 *    parses `content.rendered` and renders the registered component
 *    instead of the original HTML wherever a block of that name appears.
 *
 *    Each block component receives:
 *      - runtime    — the WPHeadlessRuntime
 *      - attrs      — block attributes (typed as `unknown` here; cast in
 *                     your component based on the block's `attributes`)
 *      - innerHTML  — string of rendered HTML inside the block wrapper
 *      - className  — the wrapper's original `class` attribute
 *
 *    Example:
 *
 *      // src/components/blocks/slider.tsx
 *      interface SliderAttrs { slides: Array<{src: string; caption: string}> }
 *      export function Slider({ attrs }: { attrs: SliderAttrs }) {
 *        return <MyReactSlider slides={attrs.slides} />
 *      }
 *
 *      // src/templates/registry.ts
 *      import { Slider } from '../components/blocks/slider'
 *      export const blockComponents: TemplateMap = {
 *        'my-plugin/slider': Slider,
 *      }
 */

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type TemplateMap = Record<string, ComponentType<any>>

export const pageTemplates: TemplateMap = {
  // 'about': PageAbout,
}

export const singleTemplates: TemplateMap = {
  // 'book': SingleBook,
}

export const blockComponents: TemplateMap = {
  // 'core/gallery': ReactGallery,
  // 'my-plugin/slider': SliderBlock,
}
