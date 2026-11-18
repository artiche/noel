import { DOCUMENT } from '@angular/common';
import { inject, Injectable } from '@angular/core';

export interface ShareEdits {
  title: string;
  url: string;
  text: string;
  comment: string;
  keepImage: boolean;
}

export interface PendingShare {
  id: string;
  edits: ShareEdits | null;
  reviewRequired: boolean;
  metadata?: { title: string; url: string; source: 'opengraph' | 'html' } | null;
}

export interface ShareQueue {
  activeId: string | null;
  items: PendingShare[];
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isPendingShare(value: unknown): value is PendingShare {
  if (!isRecord(value) || typeof value['id'] !== 'string' || !/^[a-f0-9]{64}$/.test(value['id']) ||
      typeof value['reviewRequired'] !== 'boolean') return false;
  const metadata = value['metadata'];
  if (metadata !== undefined && metadata !== null && !(isRecord(metadata) &&
      typeof metadata['title'] === 'string' && typeof metadata['url'] === 'string' &&
      (metadata['source'] === 'opengraph' || metadata['source'] === 'html'))) return false;
  const edits = value['edits'];
  return edits === null || (isRecord(edits) && typeof edits['title'] === 'string' &&
    typeof edits['url'] === 'string' && typeof edits['text'] === 'string' &&
    typeof edits['comment'] === 'string' && typeof edits['keepImage'] === 'boolean');
}

@Injectable({ providedIn: 'root' })
export class ShareStorage {
  private readonly document = inject(DOCUMENT);
  private readonly key = `noel.pending-shares:${new URL(this.document.baseURI).pathname}`;

  read(): ShareQueue {
    const storage = this.storage();
    const raw = storage.getItem(this.key);
    if (raw === null) return { activeId: null, items: [] };
    const value: unknown = JSON.parse(raw);
    if (!isRecord(value) || !Array.isArray(value['items']) || !value['items'].every(isPendingShare) ||
        !(value['activeId'] === null || typeof value['activeId'] === 'string')) {
      throw new Error('Invalid stored shares');
    }
    const items = value['items'];
    const activeId = value['activeId'];
    if ((items.length === 0) !== (activeId === null) ||
        new Set(items.map((item) => item.id)).size !== items.length ||
        (activeId !== null && !items.some((item) => item.id === activeId))) {
      throw new Error('Invalid stored shares');
    }
    return { activeId, items };
  }

  write(queue: ShareQueue): void {
    this.storage().setItem(this.key, JSON.stringify(queue));
  }

  private storage(): Storage {
    const view = this.document.defaultView;
    if (!view) throw new Error('Session storage unavailable');
    return view.sessionStorage;
  }
}
