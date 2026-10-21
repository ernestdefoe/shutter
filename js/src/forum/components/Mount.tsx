import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

interface Attrs {
  name: string;
  load: () => Promise<{ default: any }>;
  attrs: Record<string, unknown>;
}

const loaded = new Map<string, any>();

/** A component from a lazy chunk, drawn once the chunk has arrived. */
export default class Mount extends Component<Attrs> {
  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);

    if (!loaded.has(this.attrs.name)) {
      this.attrs.load().then((module) => {
        loaded.set(this.attrs.name, module.default);
        m.redraw();
      });
    }
  }

  view() {
    const Loaded = loaded.get(this.attrs.name);

    return Loaded ? <Loaded {...this.attrs.attrs} /> : <LoadingIndicator display="block" />;
  }
}
