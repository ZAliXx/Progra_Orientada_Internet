import "../styles/navbar.css";

export default function NavBar() {
  return (
    <div className="navbar">
      <div className="nav-left">
        <div className="logo">
          <img src="GoldenBoot.png"></img>
        </div>
      </div>

      <div className="nav-center">
        <button className="add-friend">
            <img className="nav-icon" src="userplus_icon_black.png"></img>
          <p>Add Friend</p>
        </button>
      </div>

      <div className="nav-right">
        <div className="nav-icon">
          <img src="settings_icon.png"></img>
        </div>
        <div className="nav-icon">
          <img src="notification_icon_black.png"></img>
        </div>
        <div className="nav-icon">
          <img src="user_icon_black.png"></img>
        </div>
      </div>
    </div>
  );
}