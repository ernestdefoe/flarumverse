import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-flarumverse.forum.page.' + key, params as any);

type Thread = {
  id: number;
  partner: number;
  forum: string | null;
  title: string;
  excerpt: string | null;
  author: string | null;
  tag: string | null;
  replies: number;
  url: string;
  postedAt: string;
};
type PartnerRef = { id: number; name: string; url: string };

/** /flarumverse: the newest discussions from paired forums, newest first. */
export default class FlarumversePage extends Page {
  threads: Thread[] = [];
  partners: PartnerRef[] = [];
  partner = '0';
  more = false;
  loading = true;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('flarumverse', extractText(t('title')));
    app.setTitle(extractText(t('title')));
    this.load(true);
  }

  load(reset: boolean) {
    this.loading = true;
    const offset = reset ? 0 : this.threads.length;

    app
      .request<{ threads: Thread[]; partners: PartnerRef[]; more: boolean }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/flarumverse/threads',
        params: { partner: this.partner, offset },
      })
      .then(
        (res) => {
          this.threads = reset ? res.threads : this.threads.concat(res.threads);
          this.partners = res.partners;
          this.more = res.more;
          this.loading = false;
          m.redraw();
        },
        () => {
          this.loading = false;
          m.redraw();
        }
      );
  }

  view() {
    const options: Record<string, string> = { '0': extractText(t('all_forums')) };
    this.partners.forEach((p) => (options[String(p.id)] = p.name));

    return (
      <PageStructure className="FlarumversePage" sidebar={() => <IndexSidebar />}>
        <div className="FlarumversePage-head">
          <p className="FlarumversePage-intro">{t('intro', { forum: app.forum.attribute('title') })}</p>
          {this.partners.length > 1 ? (
            <Select
              className="FlarumversePage-filter"
              options={options}
              value={this.partner}
              onchange={(value: string) => {
                this.partner = value;
                this.load(true);
              }}
            />
          ) : null}
        </div>

        {this.threads.length === 0 && !this.loading ? <Placeholder text={t('empty')} /> : null}

        <ul className="FlarumverseList">
          {this.threads.map((thread) => (
            <li key={thread.id} className="FlarumverseItem">
              <a className="FlarumverseItem-link" href={thread.url} rel="noopener ugc">
                <span className="FlarumverseItem-forum">{thread.forum}</span>
                <span className="FlarumverseItem-title">{thread.title}</span>
                {thread.excerpt ? <span className="FlarumverseItem-excerpt">{thread.excerpt}</span> : null}
                <span className="FlarumverseItem-meta">
                  {thread.author ? <span>{thread.author}</span> : null}
                  {thread.tag ? <span>{thread.tag}</span> : null}
                  <span>{t('replies', { count: thread.replies })}</span>
                  <span>{humanTime(new Date(thread.postedAt))}</span>
                </span>
              </a>
            </li>
          ))}
        </ul>

        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="FlarumversePage-more">
            <Button className="Button" onclick={() => this.load(false)}>
              {t('load_more')}
            </Button>
          </div>
        ) : null}
      </PageStructure>
    );
  }
}
