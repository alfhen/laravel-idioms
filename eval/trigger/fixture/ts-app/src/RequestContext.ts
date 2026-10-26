export interface User {
  id: string;
  email: string;
  roles: string[];
}

export class RequestContext {
  user: any;
  headers: Record<string, string>;
  private cache: { [key: string]: any } = {};

  constructor(headers: Record<string, string>, user?: User) {
    this.headers = headers;
    this.user = user;
  }

  get locale(): string {
    const header = this.headers['accept-language'];
    if (header) {
      return header.split(',')[0].split('-')[0];
    }
    return 'en';
  }

  hasRole(role: string): boolean {
    return this.user && this.user.roles.indexOf(role) !== -1;
  }

  async load<T>(key: string, loader: () => Promise<T>): Promise<T> {
    if (this.cache[key]) {
      return this.cache[key];
    }
    const value = await loader();
    this.cache[key] = value;
    return value;
  }
}
