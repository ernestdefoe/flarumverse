import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-flarumverse.admin.' + key, params as any);

type Partner = {
  id: number;
  url: string;
  name: string;
  description: string | null;
  status: 'incoming' | 'outgoing' | 'paired' | 'declined' | 'refused';
  threads: number;
  pairedAt: string | null;
  updatedAt: string;
  lastError: string | null;
};
type State = { url: string; max: number; partners: Partner[]; suggestions: string[] };

/**
 * Flarumverse's admin page: this forum's address, pairing with a forum (and the
 * suggested first partner), requests to approve, partners, and requests waiting.
 */
export default class FlarumverseAdminPage extends ExtensionPage {
  state: State | null = null;
  address = '';
  busy: string | null = null;
  error: string | null = null;
  notice: string | null = null;
  copied = false;

  oninit(vnode: Mithril.Vnode<any, any>) {
    super.oninit(vnode);
    this.call('GET');
  }

  call(method: string, path = '', body?: object): Promise<void> {
    return app
      .request<State>({ method, url: app.forum.attribute('apiUrl') + '/flarumverse/partners' + path, body, errorHandler: () => {} } as any)
      .then(
        (state) => {
          if (state) this.state = state;
          if (method === 'DELETE') return this.call('GET');
        },
        (e: any) => {
          this.error = e?.response?.errors?.[0]?.detail || extractText(app.translator.trans('core.lib.error.generic_message'));
        }
      )
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  pair(url: string) {
    this.busy = 'pair:' + url;
    this.error = this.notice = null;
    this.call('POST', '', { url }).then(() => {
      if (this.error) return;
      const added = this.state?.partners.find((p) => p.url.replace(/^https:\/\//, '') === url.replace(/^https?:\/\//, '').replace(/\/+$/, ''));
      this.notice = extractText(t('pair.sent', { name: added?.name || url }));
      this.address = '';
    });
  }

  act(partner: Partner, action: 'approve' | 'decline' | 'remove') {
    if (action === 'remove' && partner.status === 'paired' && !confirm(extractText(t('partner.confirm_remove', { name: partner.name })))) return;
    this.busy = action + ':' + partner.id;
    this.error = this.notice = null;
    action === 'remove' ? this.call('DELETE', '/' + partner.id) : this.call('PATCH', '/' + partner.id, { action });
  }

  content() {
    const s = this.state;
    if (!s)
      return (
        <div className="container">
          <LoadingIndicator />
        </div>
      );

    const by = (status: Partner['status']) => s.partners.filter((p) => p.status === status);
    const paired = by('paired');

    return (
      <div className="FlarumverseAdmin container">
        <div className="FlarumverseAdmin-address">
          <span>{t('your_address')}</span> <code>{s.url}</code>{' '}
          <Button
            className="Button Button--text"
            icon={this.copied ? 'fas fa-check' : 'far fa-copy'}
            onclick={() => navigator.clipboard?.writeText(s.url).then(() => ((this.copied = true), m.redraw()))}
          >
            {this.copied ? t('copied') : t('copy')}
          </Button>
        </div>

        <section className="FlarumverseAdmin-section">
          <h3>{t('pair.heading')}</h3>
          <form
            className="FlarumverseAdmin-pair"
            onsubmit={(e: Event) => {
              e.preventDefault();
              if (this.address.trim()) this.pair(this.address.trim());
            }}
          >
            <label className="sr-only" for="FlarumverseAdmin-address">
              {t('pair.label')}
            </label>
            <input
              id="FlarumverseAdmin-address"
              className="FormControl"
              type="text"
              inputmode="url"
              autocomplete="off"
              placeholder={extractText(t('pair.placeholder'))}
              value={this.address}
              oninput={(e: InputEvent) => {
                this.address = (e.target as HTMLInputElement).value;
              }}
            />
            <Button
              className="Button Button--primary"
              type="submit"
              loading={this.busy?.startsWith('pair:') && !s.suggestions.includes(this.busy.slice(5))}
              disabled={!this.address.trim()}
            >
              {t('pair.button')}
            </Button>
          </form>
          {this.error ? <p className="FlarumverseAdmin-error">{this.error}</p> : null}
          {this.notice ? <p className="FlarumverseAdmin-notice">{this.notice}</p> : null}

          {s.suggestions.length ? (
            <div className="FlarumverseAdmin-suggestions">
              <h4>{t('suggestions.heading')}</h4>
              <p className="helpText">{t('suggestions.help')}</p>
              {s.suggestions.map((url) => (
                <div className="FlarumversePartner" key={url}>
                  <div className="FlarumversePartner-info">
                    <strong>{url.replace(/^https:\/\//, '')}</strong>
                  </div>
                  <div className="FlarumversePartner-actions">
                    <Button className="Button" loading={this.busy === 'pair:' + url} onclick={() => this.pair(url)}>
                      {t('pair.button')}
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          ) : null}
        </section>

        {this.list(t('sections.requests'), by('incoming'), (p) => [
          <Button className="Button Button--primary" loading={this.busy === 'approve:' + p.id} onclick={() => this.act(p, 'approve')}>
            {t('partner.approve')}
          </Button>,
          <Button className="Button" loading={this.busy === 'decline:' + p.id} onclick={() => this.act(p, 'decline')}>
            {t('partner.decline')}
          </Button>,
        ])}

        <section className="FlarumverseAdmin-section">
          <h3>{t('sections.paired', { count: paired.length, max: s.max })}</h3>
          {paired.length ? this.rows(paired, (p) => [this.removeButton(p, 'remove')]) : <p className="helpText">{t('sections.none_paired')}</p>}
        </section>

        {this.list(t('sections.waiting'), by('outgoing'), (p) => [this.removeButton(p, 'cancel')])}
        {this.list(t('sections.refused'), by('refused'), (p) => [this.removeButton(p, 'forget')])}
        {this.list(t('sections.declined'), by('declined'), (p) => [this.removeButton(p, 'forget')])}

        <section className="FlarumverseAdmin-section">
          <h3>{t('how.heading')}</h3>
          <ul className="FlarumverseAdmin-how">
            <li>{t('how.items.shared')}</li>
            <li>{t('how.items.sent')}</li>
            <li>{t('how.items.refresh')}</li>
          </ul>
        </section>
      </div>
    );
  }

  removeButton(p: Partner, label: 'remove' | 'cancel' | 'forget') {
    return (
      <Button className="Button" loading={this.busy === 'remove:' + p.id} onclick={() => this.act(p, 'remove')}>
        {t('partner.' + label)}
      </Button>
    );
  }

  list(heading: Mithril.Children, partners: Partner[], actions: (p: Partner) => Mithril.Children[]) {
    if (!partners.length) return null;

    return (
      <section className="FlarumverseAdmin-section">
        <h3>{heading}</h3>
        {this.rows(partners, actions)}
      </section>
    );
  }

  rows(partners: Partner[], actions: (p: Partner) => Mithril.Children[]) {
    return partners.map((p) => (
      <div className="FlarumversePartner" key={p.id}>
        <div className="FlarumversePartner-info">
          <a href={p.url} target="_blank" rel="noopener noreferrer">
            <strong>{p.name}</strong>
          </a>
          <span className="FlarumversePartner-url">{p.url.replace(/^https:\/\//, '')}</span>
          {p.description ? <span className="FlarumversePartner-description">{p.description}</span> : null}
          <span className="FlarumversePartner-meta">
            {p.status === 'paired'
              ? [t('partner.paired_since', { ago: humanTime(p.pairedAt!) }), ' · ', t('partner.threads', { count: p.threads })]
              : t('partner.asked', { ago: humanTime(p.updatedAt) })}
          </span>
          {p.lastError ? <span className="FlarumversePartner-error">{t('partner.unreachable', { error: p.lastError })}</span> : null}
        </div>
        <div className="FlarumversePartner-actions">{actions(p)}</div>
      </div>
    ));
  }
}
