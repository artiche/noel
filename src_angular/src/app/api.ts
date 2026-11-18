import { DOCUMENT } from '@angular/common';
import { HttpClient, HttpContext, HttpContextToken, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, map, Observable, of, throwError } from 'rxjs';
import type { Op } from 'quill';

export const FIRST_YEAR = 2021;
export const BACKGROUND_SESSION_REQUEST = new HttpContextToken(() => false);

export interface DrawAssignment {
  giver: string;
  recipient: string;
  giverKey: string;
  recipientKey: string;
}

export interface Draw {
  year: number;
  assignmentsByGiver: Record<string, string>;
  lines: string[];
  assignments: DrawAssignment[];
}

export interface Session {
  authenticated: true;
  isAdmin: boolean;
  login: string;
  email: string;
  participant: string | null;
  participantKey: string | null;
}

export interface AdminUser {
  login: string;
  email: string;
  isAdmin: boolean;
}

export interface Participant {
  name: string;
  login: string | null;
  suggestedLogin: string;
  forbiddenRecipients: string[];
}

export interface AdminData {
  users: AdminUser[];
  participants: Participant[];
}

export interface Wishlist {
  participant: string;
  ops: Op[];
  revision: string;
  giver: { name: string; key: string } | null;
  recipient: { name: string; key: string; year: number } | null;
}

export interface RecipientWishlist {
  participant: string;
  year: number;
  ops: Op[];
}

export interface ShareToken {
  id: string;
  createdAt: number | null;
}

export interface SharedDraft {
  title: string;
  url: string;
  text: string;
  image: string | null;
  source: 'android' | 'ios';
  expiresAt: number;
}

export interface SharedContent {
  title: string;
  url: string;
  text: string;
  keepImage: boolean;
}

export type ShareMetadata =
  | { status: 'found'; title: string; source: 'opengraph' | 'html' }
  | { status: 'none' | 'unavailable' | 'not_needed'; title: null; source: null };

export class InvalidNotificationResponseError extends Error {}

interface ApiResponse<T> {
  data: T;
}

export function isMissingDraw(error: unknown): boolean {
  return error instanceof HttpErrorResponse &&
    error.status === 404 && error.error?.error?.code === 'draw_not_found';
}

@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = new URL('api/', inject(DOCUMENT).baseURI).pathname;

  login(login: string, password: string): Observable<void> {
    return this.http.post<ApiResponse<{ authenticated: boolean }>>(
      `${this.base}login`, { login, password }, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  logout(): Observable<void> {
    return this.http.post<ApiResponse<{ authenticated: boolean }>>(
      `${this.base}logout`, {}, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  session(): Observable<Session> {
    return this.http.get<ApiResponse<Session>>(
      `${this.base}session`, { withCredentials: true },
    ).pipe(map((response) => {
      if (response.data?.authenticated !== true || typeof response.data.isAdmin !== 'boolean' ||
          typeof response.data.login !== 'string' || typeof response.data.email !== 'string' ||
          !((response.data.participant === null && response.data.participantKey === null) ||
            (typeof response.data.participant === 'string' && typeof response.data.participantKey === 'string' &&
              response.data.participantKey !== ''))) {
        throw new Error('Invalid session response');
      }
      return response.data;
    }));
  }

  updateAccount(currentPassword: string, email?: string, newPassword?: string): Observable<void> {
    return this.http.post<ApiResponse<{ updated: boolean }>>(
      `${this.base}account`, { currentPassword, ...(email === undefined ? {} : { email }),
        ...(newPassword === undefined ? {} : { newPassword }) }, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  shareTokens(): Observable<ShareToken[]> {
    return this.http.get<ApiResponse<{ tokens: unknown }>>(`${this.base}share-tokens`, { withCredentials: true })
      .pipe(map((response) => {
        const tokens = response.data?.tokens;
        if (!Array.isArray(tokens) || !tokens.every((token: unknown): token is ShareToken =>
          token !== null && typeof token === 'object' && 'id' in token && typeof token.id === 'string' &&
          /^[a-f0-9]{32}$/.test(token.id) && 'createdAt' in token &&
          (token.createdAt === null || (typeof token.createdAt === 'number' &&
            Number.isSafeInteger(token.createdAt) && token.createdAt >= 0 && token.createdAt <= 8_640_000_000_000))) ||
          new Set(tokens.map((token) => token.id)).size !== tokens.length) {
          throw new Error('Invalid share token list');
        }
        return tokens;
      }));
  }

  createShareToken(): Observable<{ id: string; token: string }> {
    return this.http.post<ApiResponse<{ id: unknown; token: unknown }>>(
      `${this.base}share-tokens`, {}, { withCredentials: true },
    ).pipe(map((response) => {
      const data = response.data;
      if (!data || typeof data.id !== 'string' || !/^[a-f0-9]{32}$/.test(data.id) ||
          typeof data.token !== 'string' || !/^[a-f0-9]{64}$/.test(data.token)) {
        throw new Error('Invalid share token creation response');
      }
      return { id: data.id, token: data.token };
    }));
  }

  revokeShareToken(id: string): Observable<void> {
    return this.http.post<ApiResponse<{ revoked: boolean }>>(
      `${this.base}share-tokens/revoke`, { id }, { withCredentials: true },
    ).pipe(map((response) => {
      if (response.data?.revoked !== true) throw new Error('Invalid share token revocation response');
    }));
  }

  openSharedDraft(draftId: string): Observable<SharedDraft> {
    return this.http.post<ApiResponse<SharedDraft>>(
      `${this.base}share-drafts/open`, { draftId }, { withCredentials: true },
    ).pipe(map((response) => {
      const data = response.data;
      if (!data || typeof data.title !== 'string' || typeof data.url !== 'string' || typeof data.text !== 'string' ||
          (data.image !== null && (typeof data.image !== 'string' || data.image.length > 6_666_800 ||
            !/^data:image\/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$/.test(data.image))) ||
          (data.source !== 'android' && data.source !== 'ios') ||
          !Number.isSafeInteger(data.expiresAt) || data.expiresAt < 0 || data.expiresAt > 8_640_000_000) {
        throw new Error('Invalid shared draft response');
      }
      return data;
    }));
  }

  sharedDraftMetadata(draftId: string): Observable<ShareMetadata> {
    return this.http.post<ApiResponse<ShareMetadata>>(
      `${this.base}share-drafts/metadata`, { draftId },
      { withCredentials: true, context: new HttpContext().set(BACKGROUND_SESSION_REQUEST, true) },
    ).pipe(map((response) => {
      const data = response.data;
      if (!data || !(
        (data.status === 'found' && typeof data.title === 'string' && data.title.trim() !== '' &&
          new TextEncoder().encode(data.title).length <= 4096 &&
          (data.source === 'opengraph' || data.source === 'html')) ||
        ((data.status === 'none' || data.status === 'unavailable' || data.status === 'not_needed') &&
          data.title === null && data.source === null)
      )) {
        throw new Error('Invalid share metadata response');
      }
      return data;
    }));
  }

  confirmSharedDraft(draftId: string, revision: string, content: SharedContent): Observable<{ alreadyConfirmed: boolean }> {
    return this.http.post<ApiResponse<{ revision: string; alreadyConfirmed: boolean }>>(
      `${this.base}share-drafts/confirm`, { draftId, revision, ...content }, { withCredentials: true },
    ).pipe(map((response) => {
      const data = response.data;
      if (!data || typeof data.revision !== 'string' || !/^[a-f0-9]{64}$/.test(data.revision) ||
          typeof data.alreadyConfirmed !== 'boolean') {
        throw new Error('Invalid share confirmation response');
      }
      return { alreadyConfirmed: data.alreadyConfirmed };
    }));
  }

  cancelSharedDraft(draftId: string): Observable<void> {
    return this.http.post<ApiResponse<{ cancelled: boolean }>>(
      `${this.base}share-drafts/cancel`, { draftId }, { withCredentials: true },
    ).pipe(map((response) => {
      if (response.data?.cancelled !== true) throw new Error('Invalid share cancellation response');
    }));
  }

  sharedDraftStatus(draftId: string): Observable<boolean> {
    return this.http.post<ApiResponse<{ confirmed: boolean }>>(
      `${this.base}share-drafts/status`, { draftId }, { withCredentials: true },
    ).pipe(map((response) => {
      if (typeof response.data?.confirmed !== 'boolean') throw new Error('Invalid share status response');
      return response.data.confirmed;
    }));
  }

  adminData(): Observable<AdminData> {
    return this.http.get<ApiResponse<AdminData>>(`${this.base}admin/users`, { withCredentials: true })
      .pipe(map((response) => response.data));
  }

  createUser(user: AdminUser & { participant?: string }): Observable<void> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}admin/users`, user, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  editUser(user: { login: string; email: string; isAdmin: boolean; password?: string }): Observable<void> {
    return this.http.patch<ApiResponse<unknown>>(`${this.base}admin/users`, user, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  deleteUser(login: string): Observable<void> {
    return this.http.delete<ApiResponse<unknown>>(`${this.base}admin/users`, { body: { login }, withCredentials: true })
      .pipe(map(() => undefined));
  }

  adminReset(login: string): Observable<void> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}admin/users/reset`, { login }, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  createParticipant(name: string): Observable<void> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}admin/participants`, { name }, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  editParticipant(name: string, newName: string, forbiddenRecipients: string[]): Observable<void> {
    return this.http.patch<ApiResponse<unknown>>(`${this.base}admin/participants`,
      { name, newName, forbiddenRecipients }, { withCredentials: true }).pipe(map(() => undefined));
  }

  deleteParticipant(name: string): Observable<void> {
    return this.http.delete<ApiResponse<unknown>>(`${this.base}admin/participants`, { body: { name }, withCredentials: true })
      .pipe(map(() => undefined));
  }

  requestPasswordReset(login: string): Observable<void> {
    return this.http.post<ApiResponse<{ passwordResetRequested: boolean }>>(
      `${this.base}password/forgot`, { login }, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  wishlist(): Observable<Wishlist> {
    return this.http.get<ApiResponse<Wishlist>>(`${this.base}wishlist`, { withCredentials: true })
      .pipe(map((response) => {
        const giver = response.data?.giver;
        if (giver !== null && (!giver || typeof giver.name !== 'string' || giver.name === '' ||
            typeof giver.key !== 'string' || giver.key === '')) {
          throw new Error('Invalid wishlist giver');
        }
        return response.data;
      }));
  }

  saveWishlist(ops: Op[], revision: string): Observable<{ revision: string }> {
    return this.http.put<ApiResponse<{ revision: string }>>(
      `${this.base}wishlist`, { ops, revision }, { withCredentials: true },
    ).pipe(map((response) => response.data));
  }

  recipientWishlist(target: string): Observable<RecipientWishlist> {
    return this.http.get<ApiResponse<RecipientWishlist>>(
      `${this.base}wishlist/recipient`, { params: { target }, withCredentials: true },
    ).pipe(map((response) => response.data));
  }

  notifyGiver(expectedGiver: string): Observable<string> {
    return this.http.post<ApiResponse<{ sent: boolean; giver: string }>>(
      `${this.base}wishlist/notify`, { expectedGiver }, { withCredentials: true },
    ).pipe(map((response) => {
      if (response.data?.sent !== true || typeof response.data.giver !== 'string' || response.data.giver === '') {
        throw new InvalidNotificationResponseError('Invalid wishlist notification response');
      }
      return response.data.giver;
    }));
  }

  draw(year: number): Observable<Draw> {
    return this.http.get<ApiResponse<Draw>>(
      `${this.base}draw/${year}`, { withCredentials: true },
    ).pipe(map((response) => {
      const draw = response.data;
      if (!draw || !Array.isArray(draw.assignments) ||
          !draw.assignments.every((assignment) => assignment !== null && typeof assignment === 'object' &&
            typeof assignment.giver === 'string' && typeof assignment.recipient === 'string' &&
            typeof assignment.giverKey === 'string' && assignment.giverKey !== '' &&
            typeof assignment.recipientKey === 'string' && assignment.recipientKey !== '')) {
        throw new Error('Invalid draw assignments');
      }
      return draw;
    }));
  }

  latestDraw(year: number, minimumYear = FIRST_YEAR): Observable<Draw | null> {
    if (year < minimumYear) {
      return of(null);
    }
    return this.draw(year).pipe(
      catchError((error: unknown) =>
        isMissingDraw(error) ? this.latestDraw(year - 1, minimumYear) : throwError(() => error),
      ),
    );
  }
}
