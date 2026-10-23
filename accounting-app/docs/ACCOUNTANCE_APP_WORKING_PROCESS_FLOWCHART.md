# Accountance Web App - Working Process Flowchart

This document explains the end-to-end working process of the Accountance web application with flowcharts for easy understanding.

## 1. High-Level Architecture

```mermaid
graph TD
    A[Browser - User] --> B[Next.js Frontend - BFF]
    B --> C[Laravel Backend API]
    C --> D[(MySQL Database)]
    
    B --> E[Encrypted HTTP-Only Session Cookie]
    C --> F[JWT Auth Token]
    
    style A fill:#e1f5fe
    style B fill:#f3e5f5
    style C fill:#fff3e0
    style D fill:#e8f5e9
    style E fill:#fce4ec
    style F fill:#fce4ec
```

**Flow Explanation:**
1. User interacts through the browser
2. Frontend acts as a Backend-For-Frontend (BFF) using Next.js 16
3. BFF communicates with Laravel 13 REST API
4. Data is persisted in MySQL 8

## 2. Authentication Flow

```mermaid
flowchart TD
    A[User visits app] --> B{Has valid session?}
    B -->|No| C[Redirect to /login via proxy.ts]
    C --> D[Login Page]
    D --> E[Submit credentials]
    E --> F[POST /api/session/login - BFF Route]
    F --> G[Forward to Laravel POST /api/auth/login]
    G --> H{Valid credentials?}
    H -->|No| I[Return 401 - Show error]
    I --> D
    H -->|Yes| J[Laravel returns JWT token + user data]
    J --> K[BFF encrypts token in HTTP-Only cookie]
    K --> L[Set session cookie & redirect to app]
    L --> M[User in authenticated app]
    
    B -->|Yes| M
    
    style H fill:#fff9c4
    style K fill:#fce4ec
    style M fill:#c8e6c9
```

### Authentication Endpoints

| Flow | BFF Route | Laravel Route | Purpose |
|---|---|---|---|
| Login | `POST /api/session/login` | `POST /api/auth/login` | User authentication with JWT |
| Register | `POST /api/session/register` | `POST /api/auth/register` | New user registration (grants Staff role) |
| Logout | `POST /api/session/logout` | `POST /api/auth/logout` | Invalidate session |
| Get Current User | `GET /api/session/me` | `GET /api/auth/me` | Fetch authenticated user details |
| Forgot Password | `POST /api/session/forgot-password` | `POST /api/auth/forgot-password` | Send password reset email |
| Reset Password | `POST /api/session/reset-password` | `POST /api/auth/reset-password` | Reset password with token |
| Change Password | `PUT /api/session/password` | `PUT /api/auth/password` | Change password while logged in |
| Email Verification | `POST /api/session/verify-email/*` | Various `/api/auth/email/verification-notification` & `/api/auth/email/verify/*` | Email verification flow |

## 3. BFF Proxy Architecture

```mermaid
flowchart TD
    A[Browser Request] --> B{Route Type}
    B -->|Session routes /api/session/*| C[BFF Session Handlers]
    B -->|Business API /api/backend/*| D[BFF Backend Proxy]
    
    C --> E[Read encrypted session cookie]
    E --> F[Decrypt session payload]
    F --> G[Extract JWT token]
    G --> H[Forward to Laravel with Authorization header]
    
    D --> I[Validate session exists]
    I --> J[Get active company from cookie/session]
    J --> K[Inject Authorization: Bearer token]
    K --> L[Inject X-Company-Id header]
    L --> M[Forward request to Laravel API]
    M --> N[Stream response back to browser]
    
    H --> N
    
    style D fill:#e1bee7
    style M fill:#ffe0b2
    style N fill:#c8e6c9
```

**Key Security Features:**
- Browser never sees or stores JWT tokens directly
- All tokens are encrypted in HTTP-Only session cookies (AES-256-GCM)
- BFF injects auth headers server-to-server
- Active company context injected via `X-Company-Id`
- Path validation & traversal protection in proxy
- 401 responses clear session and redirect to login

## 4. Route Protection & Middleware

```mermaid
flowchart TD
    A[Incoming Request to Laravel] --> B{Is API route /api/*?}
    B -->|Yes| C[Apply global middleware]
    C --> D[AssignRequestId]
    D --> E{Route requires auth?}
    E -->|No - Public routes| F[auth, health, register, login, forgot/reset password]
    E -->|Yes| G[auth:api middleware]
    G --> H[auth.fresh middleware - Check user active & token password version]
    H --> I{Route requires company context?}
    I -->|Yes - Business modules| J[company.context middleware - Resolve active company]
    J --> K[Permission/Role checks]
    K --> L[Execute Controller]
    I -->|No - Auth/Company management| L
    F --> L
    L --> M[Return JSON response with ApiResponse wrapper]
    
    style G fill:#ffcdd2
    style H fill:#ffcdd2
    style J fill:#f8bbd0
    style K fill:#f8bbd0
```

**Middleware Stack:**
- `auth:api` - JWT authentication
- `auth.fresh` - Ensures user is active and token not invalidated by password change
- `company.context` - Validates user belongs to active company and resolves context
- `permission:*` / `role:*` - Spatie Permission checks
- `throttle:*` - Rate limiting (auth, login, register, api, etc.)

## 5. User Onboarding & Company Setup Flow

```mermaid
flowchart TD
    A[New user registers] --> B[User created with Staff role]
    B --> C[Email verification sent]
    C --> D[User logs in]
    D --> E[Check if user belongs to any company]
    E -->|No companies| F[Redirect to company onboarding]
    F --> G[User must create first company OR be invited]
    G --> H{User creates company?}
    H -->|Yes| I[POST /api/companies]
    I --> J[Company created, user becomes member]
    J --> K[Set as default company]
    K --> L[Redirect to dashboard]
    H -->|No - waiting for invite| M[Limited access - only company-related views]
    E -->|Has companies| N[Set active company from default]
    N --> L
    
    style B fill:#e1f5fe
    style G fill:#fff9c4
    style L fill:#c8e6c9
```

**Notes:**
- Self-registered users get `Staff` role with minimal permissions (`companies.view` only)
- Staff cannot create companies by default - requires proper permissions
- First company setup is critical for accessing business modules
- Company membership is many-to-many with `is_default` pivot flag

## 6. Core Business Modules Flow

```mermaid
flowchart TD
    A[Authenticated with Company Context] --> B{Select Module}
    
    B --> C[Sales]
    B --> D[Purchasing]
    B --> E[Accounting]
    B --> F[Inventory/Assets]
    B --> G[Reports & Settings]
    
    C --> C1[Customers]
    C1 --> C2[Sales Invoices]
    C2 --> C3[Customer Receipts]
    C3 --> C4[Credit/Debit Notes]
    
    D --> D1[Suppliers]
    D1 --> D2[Purchase Bills]
    D2 --> D3[Supplier Payments]
    
    E --> E1[Chart of Accounts]
    E1 --> E2[Journals & Ledger]
    E2 --> E3[Periods/Financial Years]
    E3 --> E4[Bank Reconciliation]
    E4 --> E5[Taxes]
    
    F --> F1[Fixed Assets]
    F1 --> F2[Budgets]
    F2 --> F3[Dimensions]
    
    G --> G1[Financial Reports]
    G1 --> G2[Company Settings]
    G2 --> G3[Users & Roles]
    G3 --> G4[Currencies & FX Rates]
    
    C4 --> H[Journal Entries Auto-Created]
    D3 --> H
    E2 --> H
    E4 --> H
    
    H --> I[General Ledger Updated]
    I --> J[Financial Reports Reflect Changes]
    
    style A fill:#e1f5fe
    style H fill:#fff3e0
    style I fill:#f3e5f5
    style J fill:#c8e6c9
```

## 7. Document Lifecycle (Sales Invoice Example)

```mermaid
sequenceDiagram
    participant U as User
    participant F as Frontend
    participant B as BFF Proxy
    participant L as Laravel API
    participant DB as Database
    
    U->>F: Create Sales Invoice
    F->>B: POST /api/backend/sales/invoices
    B->>L: Forward with auth + company context
    L->>L: Validate permissions (sales.invoices.create)
    L->>L: Validate customer, items, taxes
    L->>DB: Create invoice (status: draft)
    DB-->>L: Invoice created
    L-->>B: Return invoice data
    B-->>F: Return response
    F-->>U: Show invoice in draft
    
    U->>F: Post Invoice
    F->>B: POST /api/backend/sales/invoices/{id}/post
    B->>L: Forward request
    L->>L: Check permissions (sales.invoices.post)
    L->>L: Validate period open, accounts valid
    L->>DB: Create Journal Entry
    L->>DB: Update invoice status to posted
    L->>DB: Update customer balance
    DB-->>L: All changes committed
    L-->>B: Success response
    B
